# API 設計案

## 状態・共通契約

FR-09のローカル初期投入はWeb APIを追加せず、OPS-02/03のArtisanコマンドで実装しました。既存12 APIは通常public/index.php経由でも検証済みです。[ローカルHTTPと初期投入](demo.md)を参照してください。React・Vite proxy・公開経路は未検証です。

2026-09-27に認証API-01～04と依頼API-05～07、09-28に担当/状態API-08～10を実装しました。続く今回の工程でFR-05/08・AC-14のコメントを採用し、API-11/12も実装しています。認証はSanctum Cookie、PostgreSQL session、無操作30分・絶対8時間、正規化メール5回/IP30回（各60秒）を維持します。[コメント実装](comments.md)、[担当/状態](request-workflow.md)、[依頼API](requests.md)、[認証記録](authentication.md)、[開発手順](development.md)を参照してください。

2026-09-22 作成、09-27認証部分を更新。[DB](database.md)がコード・型の定義元、[画面](screens.md)が呼出元、[横断対応表](design-review.md)がFR / ACの追跡先です。JWT、一般会員登録、編集・削除、役割変更APIは提供しません。FR-09はDB文書のOPS-02 / OPS-03へ対応付けます。

同一オリジンのJSON APIとLaravel SanctumのCookie認証を採用します。SPAは `Accept: application/json`、bodyがある場合 `Content-Type: application/json` を送り、Cookieを同一オリジンへ送信します。IDは正のbigint範囲内の数字文字列、先頭ゼロなし。業務APIの不正path IDは404、bodyの不正IDは422、versionは1～2147483647のJSON整数という案を維持します。日時はUTCのRFC 3339（小数6桁+Z）、表示はJST。種別・役割・状態は [DBの共通表現](database.md)と同じ英字コードです。

全成功・失敗応答に `Cache-Control: private, no-store` を付けます（204 も対象）。CloudFront は default の CachingDisabled と AllViewer、エラーの最小 TTL も設定可能なものは 0。[基本設計](architecture.md)の通り API の失敗を SPA の HTML / 200 に置き換えません。CORS の広い許可、localStorage の認証情報、service worker による API 保存は使いません。

入力はendpointごとの許可リストで取り出し、一括代入しません。POST/PATCHのbodyはJSON objectのみ、重複key・不正JSONは400、JSON以外415、全体64KiB上限413を実装しています。業務テキストの整形・上限はDB文書と同じです。未知の一般項目は422、以下の保護項目は当該操作の許可項目でない限り **403**：`id`、`requester_id`、`author_id`、`service_request_id`、`assignee_id`、`assignee_role`、`status`、`role`、`is_active`、`auth_version`、`password`、`created_at`、`updated_at`、`version`。`expected_version` は指定する更新APIだけが受け付ける制御項目です。queryも許可リストで検査し、所有者指定・sort・per_pageで範囲を拡大させません。

## 認証・CSRF・失効・試行制限

- 初回は API-01 → API-02 → API-04。XSRF-TOKEN Cookie を URL decode して、POST / PATCH の `X-XSRF-TOKEN` へ送信。CSRF Cookie だけで認証済みとは扱わない。ログイン・ログアウトにも CSRF 検査を適用する。
- セッション Cookie は Secure / HttpOnly / SameSite=Lax / Path=/ / Domain 未指定。XSRF 用だけ JS から読める HttpOnly=false。公開環境の HTTPS 情報・trusted proxy の設定は基本設計に従う。
- database session、**無操作30分・ログインから絶対8時間**を採用。payloadにauthenticated_at・last_authenticated_activity_at・認証時のauth_versionを保存。保護APIの2xx成功時に活動時刻だけ更新し、絶対期限は延長しない。経過が1800秒 / 28800秒に達した時点で拒否。Laravel標準last_activityは保存・清掃用と区別し、毎回usersのis_active / auth_versionと照合する。CSRF取得や失敗応答では認証活動時刻を更新しない。
- ログイン成功時は session ID を更新し、旧 ID を無効化。ログアウトは現セッションを破棄・CSRF 更新。アカウント停止・公開終了は全セッションを失効させる。失効済み / 不存在 / 停止アカウントの保護要求は共通 401。期限切れデータ清掃の実行を待って拒否する設計にしない。
- API-01～API-04はセッション読取前から保存・commitまでPostgreSQLのtransaction advisory lockで直列化する。**旧案の30秒lease / 20秒強制終了条件は採用しない**。CLIでは要求全体の20秒強制終了を保証できず、leaseだけ切れると古い保存が復活するため。競合時の取得待機は単調時計で最大約5秒、失敗503。ロック自体はtransaction終了まで期限切れしない。DB SQL待機設定と実測の範囲、残存リスクは [認証設計](authentication.md)に記録。API-05以降の導入時も同じ順序を守る案とする。
- ログインは**正規化メール単位5回/60秒、信頼できる送信元IP単位30回/60秒**を採用。最初の試行から60秒の固定window、成功も加算し成功時にリセットしない。IP枠超過なら先に429、それ以外はメール枠超過で429、該当windowの残秒を整数Retry-Afterで返す。不存在・停止も同じ枠・同じ401文面。DB cacheのkeyは用途別HMACでメール/IPを平文保存しない。判定・加算はPostgreSQL transaction lockで原子的にする。IPは独立transactionでCSRF前に確定、メールはsession transaction終了時に確定・lock解放する実装へ調整した（nested transactionは外側commitまでlockを保持するため）。外部X-Forwarded-Forは信頼しない。
- 上記制限は業務認可より前の入口制御。CSRF 不正を送って回数制限を回避しないよう、ログイン IP 枠は CSRF 検証前にも適用する。正規化可能なメール枠は CSRF 成功後・認証照合前に適用する。429 試験以外では制限枠に余裕を用意する。DB cache 障害時は 503 とし、制限なしで通さない。

期限・回数は2026-09-27の利用者指示で採用しました。lock方式と入力形式の実装補足は [認証記録](authentication.md)を参照してください。[Laravel Sanctum](https://laravel.com/docs/13.x/sanctum)、[Session blocking](https://laravel.com/docs/13.x/session#session-blocking)、[PostgreSQLのadvisory lock](https://www.postgresql.org/docs/18/explicit-locking.html#ADVISORY-LOCKS)を公式資料と導入済みソースで確認しました。公開HTTPS・proxy経路はAWSで別途検証します。

## 共通エラーと判定順

入口の method / size / JSON / rate limit、CSRF の後、**認証 → URL 対象の閲覧可否 → 操作権・保護項目 → 入力 → lock 内の再確認・version → 業務状態**の順です。グローバルな route model binding で認可前に情報を返さず、社員の query は requester_id=認証中 ID に制限します。本文の user ID を閲覧条件には使いません。

| HTTP | error.code の例 | 条件・DB の扱い |
| --- | --- | --- |
| 401 | unauthenticated / invalid_credentials | 認証なし・期限切れ・停止、誤資格情報。保護本文を返さない |
| 403 | forbidden | 自分の依頼への許可されない操作、保護項目追加。役割・所有者を変更しない |
| 404 | not_found | 対象なしと閲覧不可を同じ文面にする。不正 path ID・未提供ルートも本文なし |
| 409 | stale_version / invalid_transition / request_completed / invalid_assignee_state / no_change | 古い版、禁止遷移、完了、停止担当者、同じ担当等。業務更新は rollback |
| 419 | csrf_mismatch | CSRF 不足・不一致。業務処理なし。変更系の未認証要求でも先に 419 になり得る |
| 422 | validation_failed | 必須・型・長さ・列挙値、担当候補不正、未知の一般項目、page 不正 |
| 429 | rate_limited | ログイン試行制限。Retry-After 秒待つ。パスワード照合・業務変更なし |
| 400 / 413 / 415 | bad_request / payload_too_large / unsupported_media_type | 構文・サイズ・形式が不正。業務変更なし |
| 405 | method_not_allowed | 既知ルートに未提供 method。Allow を返す |
| 500 / 503 | internal_error / temporarily_unavailable | 想定外エラー / DB・lock 等利用不可。SQL・stack trace・入力値を返さない |

業務 DB 不変とは users / service_requests / comments の業務項目を指し、試行制限 counter・session の失効記録等まで無変更という意味ではありません。入口で拒否する HTTP 応答が AWS / Nginx 由来の場合は JSON でない可能性があるため、画面は Content-Type を確認し安全な共通エラーへ変換します。

```json
{
  "error": {
    "code": "validation_failed",
    "message": "入力内容を確認してください。",
    "fields": {"title": ["タイトルは100文字以内で入力してください。"]}
  }
}
```

`fields` は 422 のときだけ存在します。409 に他人の情報や最新データを混ぜず、画面が認可された GET で再取得します。404 は常に `{"error":{"code":"not_found","message":"対象が見つかりません。"}}`。トークン・パスワード・入力値・ハッシュはエラーへ反射しません。

## 応答モデル・ページング

最小の利用者参照は `UserRef={id,display_name}`、ログイン本人だけ `CurrentUser={id,display_name,role}`。メール・is_active・auth_version は不要なので返しません。`RequestSummary={id,title,category,requester,assignee,status,version,created_at}`。`assignee` は UserRef または null。`RequestDetail` は Summary に body・updated_at を追加。`Comment={id,body,author,created_at}` で、親は URL から分かるため親 ID を重複返却しません。

一覧・コメント・担当候補は `page` だけ指定可（省略1、先頭ゼロなしの十進正整数、上限2147483647）。20件固定。成功はdata配列とmeta={current_page,per_page,total,last_page}、last_pageは最小1、範囲外は200 + 空配列。本人に許可されたqueryからtotalを求め、他人の件数を含めません。依頼一覧・コメント・担当候補はCTEからCOUNTとページ行を単一SELECTで取得し、READ COMMITTEDでも同じsnapshotを使います。認証・session保存のtransaction途中でREAD ONLY / REPEATABLE READへ変更する旧案は採用しません（[理由](requests.md)）。別ページ間の挿入によるずれは許容します。

依頼は created_at DESC, id DESC、コメントは created_at ASC, id ASC、担当候補は id ASC。cursor・sort・件数変更機能は追加しません。UI でコードは日本語ラベルへ対応付けます。

## API 一覧

全APIの追加項目拒否・共通エラー・非キャッシュは上記を継承します。保護APIは**有効な社員 / IT担当者セッション**が前提です。更新後データから応答を組み立て、session保存・外側commit成功後に送信します。

API-05～07もweb / CSRF / AuthenticatedSessionを共有します。API-06は認証時のusers共有lockを維持してINSERTし、応答を組み立てた後、session保存と外側commitの成功後に送信します。controller終了をcommitとは扱いません。session保存失敗や途中の5xxでは登録もrollbackします。詳細JSONにcommentsは追加せず、専用API-11でページ単位に取得します。

API-11/12も同じmiddlewareを共有します。GETは事前の親閲覧確認後、認可付き親・COUNT・子/投稿者取得を単一SELECTで再検査します。POSTは本文bodyだけを受け付け、expected_versionは不要（追加した場合422）、保護項目は403。本人users共有lock → 親FOR UPDATE → 最新の閲覧権/未完了を再確認 → 本人を投稿者にしてINSERT → session保存 → 外側commit → 201の順です。親version/updated_atは更新しません。完了後投稿409、完了後閲覧可、編集/削除・内部限定は未提供です。[競合・rollback検証](comments.md)を参照してください。

API-08～10も同じ認証/CSRF/外側transactionを共有します。認証本人users共有lockの後、PATCHは現担当/新候補をFOR SHARE NOWAIT、親をFOR UPDATEし、権限・版・状態・有効性を再確認します。追加usersのlock競合は503、停止確定後の別候補指定は422、親の版不一致は409。事前読取から担当が変化した場合も409です。版→完了→同一値/候補/遷移の順で判定し、同一担当は停止済みでも409 no_change。更新が必要でversion最大値なら500で無変更を維持します。[理由と実測](request-workflow.md)を参照してください。

| API ID | method / path | FR / 主な AC | 認可・入力 | 成功・応答 | 個別エラー・transaction |
| --- | --- | --- | --- | --- | --- |
| API-01 | GET /sanctum/csrf-cookie | FR-01・FR-02、AC-18・AC-19 | 未認証可、入力なし | 204、JSON body なし、XSRF / session Cookie | DB session の生成・更新。業務 transaction なし。401 を前提にせず初期化する |
| API-02 | POST /login | FR-01、AC-01・AC-02・AC-05・AC-09・AC-18・AC-19 | CSRF、email / password のみ。email 正規化・形式・最大254、password 未加工1～128。停止 / 不存在は共通拒否 | 200、data: CurrentUser、session ID 更新 | 401誤資格情報、403 role等、419、422、429。認証時 users を共有ロックして停止との順序を確定。成功時刻 / auth_version を session に保存 |
| API-03 | POST /logout | FR-02、AC-03・AC-18・AC-19 | CSRF、body は空 object、認証済みなら現 session。未認証でも正しい CSRF は処理可 | 204、body なし、session 無効化・CSRF 更新 | 419不足 / 不正、403保護項目、422未知項目。認証状態を新たに作らない。session ロックで並行保存を制御 |
| API-04 | GET /api/me | FR-01・FR-02、AC-01・AC-03・AC-07・AC-19 | 保護 API、入力なし | 200、data: CurrentUser | 401。ユーザー照合のみ、業務書込なし |
| API-05 | GET /api/requests | FR-04、AC-06・AC-07・AC-08・AC-19 | 社員は自分、ITは全件。query page のみ | 200、data: RequestSummary[]、meta | 401、403所有者指定、422 page等。認可 scope と件数を同一読取 snapshot で計算 |
| API-06 | POST /api/requests | FR-03、AC-04・AC-05・AC-07・AC-09・AC-13・AC-18 | 社員のみ。title / body / category のみ。1～100改行不可、1～5000、3コード | 201、data: RequestDetail、Location: /api/requests/{id} | ITは403。422入力、419。users を共有ロックして社員・有効性を再確認し、requester=本人、status=open、assignee=NULL、version=1 を1 transaction で INSERT |
| API-07 | GET /api/requests/{request_id} | FR-05、AC-06・AC-07・AC-08・AC-13・AC-19 | 社員は自分、ITは全件。query なし | 200、data: RequestDetail | 401、404不可 / 不在。コメントは API-11 で別取得。関連 UserRef を含む読取は同一 snapshot |
| API-08 | GET /api/requests/{request_id}/assignee-candidates | FR-06、AC-07・AC-08・AC-10 | 閲覧可能な依頼、ITのみ、未完了。page のみ。有効な IT担当者だけ | 200、data: UserRef[]、meta | 対象不可は404、自分の依頼でも社員は403、完了は409。親共有lockで状態を保持、候補/件数は単一SELECT。候補の将来の有効性はPATCHで再確認 |
| API-09 | PATCH /api/requests/{request_id}/assignee | FR-06、AC-07～AC-10・AC-14・AC-18 | 閲覧可・ITのみ。assignee_id（必須、数字文字列またはnull）、expected_version（必須） | 200、data: RequestDetail、新 version | 404不可 / 不在、403権限、422社員 / 不在 / 停止候補・型、409古い版 / 完了 / open以外の解除 / 同一担当。DB文書の users → 親行ロックで更新 |
| API-10 | PATCH /api/requests/{request_id}/status | FR-07、AC-07～AC-09・AC-11・AC-14・AC-18 | 閲覧可・ITのみ。status（4コード）、expected_version（必須） | 200、data: RequestDetail、新 version、担当を保持 | 422未定義コード、409古い版 / 禁止遷移 / 未割当 / 停止担当者。ロック中に旧状態を確認し許可遷移だけ commit |
| API-11 | GET /api/requests/{request_id}/comments | FR-05、AC-06・AC-07・AC-08・AC-13・AC-19 | 親閲覧可（完了含む）。page のみ | 200、data: Comment[]、meta | 401、404不可 / 不在。親の閲覧条件・COUNT・子取得を同一読取 snapshot で確認 |
| API-12 | POST /api/requests/{request_id}/comments | FR-08、AC-05・AC-07～AC-09・AC-12～AC-14・AC-18 | 親閲覧可・未完了。body のみ、1～2000。社員は依頼者本人、ITは全件 | 201、data: Comment。親 version は更新しない | 404不可 / 不在、403 author_id等、422本文、409完了、419。users → 親行ロック、状態再確認、INSERT、commit |

一覧表の AC 範囲は既存の各 AC を参照します。網羅性・未提供 API の拒否（AC-15）・FR-09 管理手順は [design-review.md](design-review.md)で確認できます。担当候補を依頼配下にしたのは「完了前のみ」と親の閲覧境界を揃えるためで、社員向けのユーザー一覧にはしません。

## JSON 入出力例

以下は架空値・ダミーで、発行済みの資格情報ではありません。Cookie・CSRF 値を例へ貼りません。

API-02 要求と成功応答（API-04 の成功も同じ形）：

```json
{"email":"employee-a@example.test","password":"replace-with-demo-password"}
```

```json
{"data":{"id":"1","display_name":"社員A","role":"employee"}}
```

API-01 は body なしの GET、API-03 の要求は `{}`。両方の成功は 204 で JSON を返しません。

API-06 要求と 201 成功（API-07 も同じ詳細モデル）：

```json
{"title":"検証端末の相談","body":"架空の検証端末について相談します。","category":"inquiry"}
```

```json
{
  "data": {
    "id":"101","title":"検証端末の相談","body":"架空の検証端末について相談します。",
    "category":"inquiry","requester":{"id":"1","display_name":"社員A"},
    "assignee":null,"status":"open","version":1,
    "created_at":"2026-09-22T03:00:00.000000Z","updated_at":"2026-09-22T03:00:00.000000Z"
  }
}
```

API-05 `?page=1` の 200 例：

```json
{
  "data":[{"id":"101","title":"検証端末の相談","category":"inquiry","requester":{"id":"1","display_name":"社員A"},"assignee":null,"status":"open","version":1,"created_at":"2026-09-22T03:00:00.000000Z"}],
  "meta":{"current_page":1,"per_page":20,"total":1,"last_page":1}
}
```

API-08 `?page=1` の 200 例（候補が 20 人を超えた場合もページング）：

```json
{"data":[{"id":"3","display_name":"IT担当者X"},{"id":"4","display_name":"IT担当者Y"}],"meta":{"current_page":1,"per_page":20,"total":2,"last_page":1}}
```

API-09 要求と 200 例。解除は assignee_id を null にする（open のみ）。

```json
{"assignee_id":"4","expected_version":1}
```

```json
{"data":{"id":"101","title":"検証端末の相談","body":"架空の検証端末について相談します。","category":"inquiry","requester":{"id":"1","display_name":"社員A"},"assignee":{"id":"4","display_name":"IT担当者Y"},"status":"open","version":2,"created_at":"2026-09-22T03:00:00.000000Z","updated_at":"2026-09-22T03:01:00.000000Z"}}
```

API-10 要求と 200 例：

```json
{"status":"in_progress","expected_version":2}
```

```json
{"data":{"id":"101","title":"検証端末の相談","body":"架空の検証端末について相談します。","category":"inquiry","requester":{"id":"1","display_name":"社員A"},"assignee":{"id":"4","display_name":"IT担当者Y"},"status":"in_progress","version":3,"created_at":"2026-09-22T03:00:00.000000Z","updated_at":"2026-09-22T03:02:00.000000Z"}}
```

API-12 要求と 201 例（expected_version や author_id は不要）：

```json
{"body":"架空の追加情報です。"}
```

```json
{"data":{"id":"501","body":"架空の追加情報です。","author":{"id":"1","display_name":"社員A"},"created_at":"2026-09-22T03:03:00.000000Z"}}
```

API-11 `?page=1` の 200 例：

```json
{"data":[{"id":"501","body":"架空の追加情報です。","author":{"id":"1","display_name":"社員A"},"created_at":"2026-09-22T03:03:00.000000Z"}],"meta":{"current_page":1,"per_page":20,"total":1,"last_page":1}}
```

## 再取得と通信結果不明の扱い

API-07 の version を画面で保持し、API-09 / API-10 に expected_version として送信します。成功時に返却された詳細全体へ置き換えるので次回は新 version を使えます。409 では最新詳細を GET し、差分を見せ、担当・状態の未送信選択を破棄して選び直します。古い入力を新 version に付け替えて自動送信しません。

POST / PATCH の timeout・接続切断・5xx は **DB に届かなかったと断定できません**。無条件の自動再送、419 後の interceptor による再送、ページ再表示での POST 再実行を禁止します。登録なら本人一覧、コメントなら詳細とコメント末尾、担当・状態なら最新詳細を GET して確認する案です。同じ文面だけでは自分の送信と断定できないため、結果が不明な間は再送ボタンを無効にし、作成者への確認を案内します。自動再送・厳密な重複排除用 idempotency key は今回追加せず、その制約を UI に表示します。

GET の再読込は利用者操作で行えます。401 / 419 は認証表示とフォーム内容を消去し、ログインから CSRF を取り直します。パスワード・本文を URL や永続ストレージへ退避しません。ログアウト通信失敗時は画面を隠してもサーバー失効完了とは表示せず、API-04 で状態確認またはログアウトの明示再操作を案内します。
