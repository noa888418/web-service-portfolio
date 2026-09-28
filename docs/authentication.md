# FR-01・FR-02 認証の実装と検証

## 採用範囲（2026-09-27）

コメント工程ではAPI-11/12にも同じ認証・CSRF・本人共有lockを適用しました。本人lock → 親lock → コメントINSERT → session保存/commitの整合性は [comments.md](comments.md)に記録します。

後続工程でAPI-05～10へ同じ認証middlewareを適用しました。users共有lock・session保存と業務INSERTのcommit順、5xx時のrollback追加は [requests.md](requests.md)、担当/状態更新での追加users NOWAIT・親lock・停止競合は [request-workflow.md](request-workflow.md)に記録します。認証が先に本人をlockする順序は変更していません。以下の「今回」は認証工程当時の範囲です。83テスト・645アサーション・終了コード0は利用者のローカル再実行でも確認済みで、GitHub実行結果とは区別します。

利用者が採用したのはSanctum Cookie認証、PostgreSQL session、無操作30分・ログインから絶対8時間、正規化メール5回/60秒・信頼できるIP30回/60秒です。GET `/sanctum/csrf-cookie`、POST `/login`、GET `/api/me`、POST `/logout` を実装しました。依頼・コメント・画面・公開デモのseed・AWSは今回の対象外、業務詳細は引き続き提案です。[API契約](api.md)、[DB](database.md)、[Windows手順](development.md)と合わせて参照します。

前工程のusers検証は、利用者のWindowsでも62テスト・101アサーション・終了コード0を確認済みと報告されています。GitHub上の成功報告とは区別し、URLやSHAを補っていません。今回の作業開始時に未コミット差分はありませんでした。

## Laravel標準を利用する部分と追加処理

- Sanctum **4.3.3**を追加し、既存Laravel13.32.0・PHP8.4.25・PHPUnit13.3.4・PostgreSQL18.6は維持。Composer lockの既存package更新は0件です。Sanctumのweb guard、Laravel Session / Cookie暗号化 / Hash / Eloquentを使用し、JWTやpersonal_access_tokensは導入しません。[Sanctum公式](https://laravel.com/docs/13.x/sanctum)
- 4ルートは同一オリジン専用のweb middlewareを共有し、`/api/me`もSanctum guardで本人を取得します。別origin用の広いCORSやstateful hostリストを追加しません。SanctumのBearer取得callbackを無効にし、UserにHasApiTokensを付けません。CSRF Cookieは標準CsrfCookieControllerです。
- [User](../backend/app/Models/User.php)はAuthenticatableへ変更し、既存casts・guarded・visibleを維持。remember token列は追加せずremember me入力は422。認証では既存正規化→形式検証→Argon2id照合→有効性確認を使用し、成功時は標準SessionGuard::loginとregenerateで旧IDを削除・CSRF更新します。
- session payloadはLaravel EncryptedStoreで暗号化しJSONとして保存。DBの外側base64を暗号化の根拠にしません。APP_KEYはGit外・runtime注入を維持。独自DatabaseSessionHandlerの変更はIP / User-Agentを記録しない部分だけです。保存・読取・破棄は標準処理を使います。
- 標準session.last_activityは保存要求全般で更新されるため、成功した保護要求の活動時刻と絶対期限には使えません。専用middlewareで2時刻とauth_versionを検査し、境界 `経過 >= 上限` で失効、保護要求の2xxだけ活動時刻を更新します。CSRF取得・403等の失敗・login失敗は既存認証の期限を延ばしません。
- Laravel13のPreventRequestForgeryには同一originによるtoken省略経路とテスト時の省略があります。今回の契約に合わせて両方をoverrideで拒否し、標準の暗号化X-XSRF-TOKEN照合を必ず使用します。login / logoutも除外しません。
- ログには例外クラス名だけを記録し、message・trace・SQL・入力・Cookieを出しません。sessionに前回URLも保存しません。全応答はprivate, no-store、本人JSONはid / display_name / roleのみです。認証失敗は共通401、不正CSRF419、保護項目403、一般入力422、制限429、DB / lock利用不可503です。

## 排他・停止・ログアウトの順序と変更理由

旧案はdatabase cacheの30秒lease + 要求を20秒以内に強制終了する前提でした。現在のPHP CLIでは、I/O待ちを含む要求全体の20秒終了を保証できません。単にleaseを長くしても古い保存を防げないため、**PostgreSQL transaction advisory lockに変更**しました。標準StartSessionのセッション読取・Cookie付与・保存処理は維持し、その外側だけtransactionで包みます。[PostgreSQL公式](https://www.postgresql.org/docs/18/explicit-locking.html#ADVISORY-LOCKS)

1. POST /loginのIP枠を専用transactionで判定・加算してcommit（CSRF前）。
2. Cookie復号後、元session IDの用途別HMACから64bit advisory keyを作り、transaction lockを取得してからsessionを読みます。64bit衝突は余分な直列化となり、データを混同しません。
3. CSRF、保護項目・入力を検査。loginのメール枠は同じDB transaction内の別HMAC lockで判定・加算します。
4. login / meは対象users行をFOR SHAREで取得し、状態・auth_versionを最新値で照合します。session保存とcommitが終わるまで共有lockも保持します。
5. 管理用 [AccountSessions::revoke](../backend/app/Auth/AccountSessions.php) はusers FOR UPDATE → is_active停止（指定時）・auth_version加算 → 該当sessions削除の順でcommit。公開APIやアカウント管理画面は追加しません。

同sessionのlogoutは前の保存が終わってから読取・破棄するので、旧payloadを後から保存しません。停止は先行する共有lockの終了を待ってから保存済みsessionを削除します。停止が先なら次の認証は拒否されます。規約外の古いworkerがpayloadを復活させた場合もauth_version不一致で拒否します。ユーザーを再有効化するときも古いauth_versionへ戻さない管理契約です。

advisory lockはtransaction終了 / 接続切断まで保持され、30秒等のleaseで外れません。取得の競合待機は単調時計で5秒、失敗503。session transactionにはPostgreSQL lock_timeout=5秒、statement_timeout=10秒も設定します。ただし**要求全体が20秒以内・ネットワーク障害時も5秒以内とは保証しません**。長い停止要求ではDB接続とlockが残り、後続は503になる可用性上のリスクがあります。公開前にFPM / proxy / DB接続のtimeoutと終了時rollbackを実機検証します。HTTPテストでは約5秒（4.8～7秒の範囲）の競合拒否と、解放後の正常処理を確認します。

cache / cache_locksはLaravel database cache互換のmigrationを用意します。認証の排他は有限TTLのcache_locksではなく上記advisory lockを使います。メールcounterのnested transactionは独立commitではなく、session外側のcommitで確定・解放されます。IP→session→email→usersの順序を逆転させる処理を追加しません。DB例外時に認証処理を自動再試行しません。

## 入力・試行回数・Cookieの補足

IPは現在の直結環境のREMOTE_ADDRを使用し、X-Forwarded-For等は無視します。AWSのALB / CloudFrontを信頼する設定はまだ導入していません。公開前に正規経路だけを信頼するproxy設定と送信元IPの試験が必要です。

IP枠は不正CSRF・不正bodyも数えます。メール枠はCSRF成功後、許可項目検査とメール正規化・形式確認を通過したものを数え、password入力不正・誤照合・不存在・停止・成功を含みます。保護項目や未知項目を含む要求・不正メールはメール枠に入れずIP枠には入ります。成功してもcounterはリセットせず、最初の試行から60秒で更新。IP超過が先に判定され、それ以外はメール枠の残り時間をRetry-Afterにします。超過した枠は追加加算しません。

メール/IPのkeyはAPP_KEYを用いた用途別HMACで、入力値を平文保存しません。不存在には同じ設定コストのArgon2id計算を行い、共通401を返します。応答時間が完全に同一という保証ではありません。入力bodyは64KiB以下のJSON object、重複key（Unicode escapeによる同名も含む）・構文不正400、型 / 形式 / 長さ422、別Content-Type415。PHPのJSON decoderが重複keyを黙って上書きするため、重複だけ追加検査します。passwordにTrimStrings等を適用しません。

公開設定はSecure / HttpOnly / SameSite=Lax / host-only / Path=/。XSRF-TOKENだけHttpOnly=falseです。APP_ENV=productionではSESSION_SECURE_COOKIE=falseを指定してもSecure=trueです。ローカルとテストのHTTPに限りComposeでSecure=falseを明示しています。実HTTPSの証明書・proxy転送やブラウザーの挙動は未検証であり、本番設定を弱めて疎通を通していません。

## 検証の構成と証跡

[CookieAuthenticationTest](../backend/tests/Auth/CookieAuthenticationTest.php) は専用test-dbと既存のランダムschemaを使用し、独立したPHP HTTPプロセスを2つ起動します。[CookieBrowser](../backend/tests/Support/CookieBrowser.php) が実際のSet-Cookieを保持し、URL decodeしたXSRFを送り、CSRF取得→login→me→logout→me拒否を実通信で検査します。actingAsやCSRF middleware除外は使用しません。

時計と並行処理用ルートは [テスト専用router](../backend/tests/http-router.php) だけに置きます。専用DBガードとschema名・存在確認を通らないと起動せず、127.0.0.1にだけbindします。本体 [public/index.php](../backend/public/index.php) はそれを読み込みません。テスト時計のファイルで時刻を進め、実時間で30分・8時間待ちません。同期点とpg_stat_activityで先行lock / 停止待機を観測し、sleepの長さだけで順序を推測しません。

テスト資格情報はその一時schemaのfixture専用で、公開デモへ投入するseedや配布パスワードではありません。HTTPログは値がないことを検査して一時ファイルごと撤去します。公開のデモ資格情報は別途ランダム生成・配布方法を決める必要があります。

確認項目：2役割、未認証、共通401、旧ID拒否、logout失効、停止 / auth_version、両期限境界、CSRF、5/30回と60秒回復、偽造proxy header、保護項目、JSON / Cookieの秘匿、パスワード非加工、並行logout・停止・counter、lock待機503、cache障害503、productionのSecure強制、既存usersの回帰。

今回のGitHub実行・必須チェック設定、AWS / HTTPS / Fargate負荷、異なるホスト間の通信断・DB切断、production workerの強制終了は未検証です。session / cacheの期限切れ行を定期清掃する運用も公開前に整備します。期限切れ認証の拒否は清掃完了を待ちません。ローカルの実行結果とコマンドは [開発環境](development.md)に記録します。
