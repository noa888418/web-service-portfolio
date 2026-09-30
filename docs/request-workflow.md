# 担当者割り当て・状態変更・更新競合の実装

2026-09-30追記：ReactのSCR-04にAPI-08～10を接続し、担当候補/変更・許可状態遷移・409後の再取得/再選択を実装しました。APIの入力・認可・lock・transactionは変更していません。[画面の操作と検証](frontend.md)を参照してください。独立X/Yのブラウザーで同じ版を開き、先行更新後の古い画面を409で拒否する検証と、下記の別HTTPプロセス/DB接続による実並行処理テストは別です。コメント画面・AWSは未実装です。

この文書の「今回」「未実装」は担当/状態工程時点の記録です。その後、コメントを採用・実装し、投稿対完了の両順序と2投稿の競合を [comments.md](comments.md)で検証しています。担当/状態の本人共有lock・追加users NOWAIT・親FOR UPDATEの順序は変更していません。193テスト・2,707アサーション・終了コード0は利用者のWindowsでも確認済みです。

## 採用範囲と作業基準（2026-09-28）

FR-06・FR-07とAC-14の担当/状態更新競合を今回採用しました。有効なIT担当者全員が、担当外の依頼も操作できます。社員は候補取得を含め操作不可（本人の依頼403、他人と不存在は同じ404）。候補は有効ITのみ、ID・表示名だけ、20件・ID昇順です。

担当の割り当て/変更は完了前、解除はopenのみ。状態はopen → in_progress、in_progress → waiting_confirmation、waiting_confirmation → in_progress / completedの4遷移だけです。対応開始以降の状態変更は有効な担当者が必要で、停止済み担当者は先に変更します。完了判断はIT担当者が行い、社員承認操作・完了からの再開・完了後担当変更はありません。同一担当/状態と古いexpected_versionは409、成功時だけversionを1増やします。

コメント・React・公開デモseed・AWSは実装していません。FR-05のコメント、FR-08、AC-14のコメント投稿対完了の競合は次工程です。既存設計のこれらの案まで承認済みとは扱いません。

開始時HEADは `36ffba1ea6f6276b365be67c82cfe0fd6a21f907`、未コミット差分はありませんでした。前工程の155テスト・1,656アサーション・終了コード0は利用者のWindowsでも確認済みです。このSHAは作業基準で、GitHub CIの成功証跡ではありません。今回のコミット・pushは行わず、GitHub対象コミット・実行URL・結果は未確認です。

## 実装単位

1. [RequestWorkflowController](../backend/app/Http/RequestWorkflowController.php)、[Policy](../backend/app/Policies/ServiceRequestPolicy.php)、[routes](../backend/routes/web.php)：API-08候補GET、API-09担当PATCH、API-10状態PATCH。既存Sanctum・CSRF・停止/期限・試行制限を維持し、入力許可リストと閲覧スコープの後に業務条件を判定します。
2. [ApiBoundary](../backend/app/Http/Middleware/ApiBoundary.php)：既存POSTと同じJSON object・64KiB・重複key拒否をPATCHにも適用。[ServiceRequestController](../backend/app/Http/ServiceRequestController.php)の詳細表現を共有し、最小RequestDetailと新versionを返します。メール・認証情報・内部列を追加しません。
3. [WorkflowTest](../backend/tests/Workflow/WorkflowTest.php)、[テスト専用router](../backend/tests/http-router.php)、[CI](../.github/workflows/users.yml)：実Cookie・HTTP・PostgreSQL、障害注入、明示的な同期点による並行処理を検証。CIにWorkflow suiteと全体再実行を組み込みます。

既存service_requests migrationには必要な列・正のversion CHECK・IT複合FK・非open担当必須CHECKがあるため、DB変更は不要です。既存migration・Composer lock・Docker/Compose・接続先ガードは変更しません。DB制約は担当者の有効性、IT操作権、遷移順、版一致を代替しないためLaravelで保証します。

## 外側transactionとlock順の調整理由

認証middlewareはcontrollerより先に操作本人のusers FOR SHAREを取得します。設計時の「本人を含む全usersをID昇順で取得」には戻れません。認証を後回しにしたり、lockを途中で解放したりせず、追加の担当者行では待機しない方式を採用しました。

| 順序 | PATCHの処理 | 保持・拒否条件 |
| --- | --- | --- |
| 1 | 外側transaction → session advisory lock → session読取・CSRF → 本人users FOR SHARE | 停止/期限/auth_version照合は既存middleware。本人lockをcommitまで保持 |
| 2 | スコープ付き依頼事前読取、IT権限・保護項目・入力確認 | 社員の他人IDを先に404。所有者等を入力値から決めない |
| 3 | 現担当者・新候補から本人を除き、ID昇順にFOR SHARE NOWAIT | 待機不可は503・rollback。追加usersで循環待機を作らない。本人が担当なら既存lockを使う |
| 4 | 親依頼1行をFOR UPDATE | 待機後に閲覧権・IT権限・version・事前の担当者との一致を再確認。親取得後に新たなusers lockを追加しない |
| 5 | 現状態・担当者の有効性を照合、明示した1項目とversion/updated_atを更新 | 4xxは書込前。反映した1行から詳細JSONを組み立てる |
| 6 | session保存 → 外側commit → 成功応答送信 | controller終了はcommitではない。保存/commit失敗や途中5xxは既存LockedSessionで業務更新もrollback |

本人・担当者のactive/role値は対応する共有lock内で取得した値です。停止はその間更新できません。親取得後にこれらを再判定し、担当者が事前読取から変わっていた場合は、送信版が偶然最新でも409 stale_versionにします。未lockの新担当者を使うことを避けるためです。デッドロック等のDB例外も503にして自動再試行しません。

候補GETは認証本人共有lock → 親共有lockで未完了を確認し、候補行と件数を単一CTE SELECTの同一snapshotから取得します。親の完了処理はsession保存/commitまで待ちます。他の候補usersはlockせず、一覧取得時の候補が後で停止する可能性はPATCHで再検査します。有効なIT本人を含むため通常totalは1以上で、範囲外ページは空配列です。

停止処理は既存の単一users FOR UPDATE → 停止/auth_version更新 → sessions削除 → commitです。割り当てが先なら停止が待ち、割り当てcommit後に停止できます（履歴の担当参照は残る）。停止が先にlock中なら割り当ては即時503で取消し、停止commit後に指定すると422です。現在の担当者が停止済みなら状態変更409で、有効な担当へ変更する必要があります。

NOWAITは可用性とのトレードオフです。短い停止処理と衝突しても待って成功させず、利用者にGETで確認してから明示的に操作し直してもらいます。一方、別セッション同士の通常の親依頼更新はFOR UPDATEで待ち、先行commit後に版照合して200/409へ分かれます。既存SQLのlock_timeout=5秒・statement_timeout=10秒は維持し、これを要求全体・ネットワーク障害の上限とは扱いません。公開worker/負荷下のtimeoutは未検証です。

公式根拠（2026-09-28確認）：[PostgreSQL 18の行lockと競合](https://www.postgresql.org/docs/18/explicit-locking.html)、[SELECTのNOWAIT](https://www.postgresql.org/docs/18/sql-select.html)。FOR SHAREは停止のFOR UPDATEと競合し、追加取得のNOWAITは待たずに失敗します。これらを本サービスの認証先行順へ適用した判断です。

## 入力・エラーの補足

契約は [api.md](api.md) API-08～10と共通形式を使います。assignee_idは必須で、正のbigint数字文字列またはnull。statusは4コードの完全一致、expected_versionは1～2147483647のJSON整数です。保護項目403、未知の一般項目422、queryは候補GETのpage以外を許可しません。

lock取得後は版/事前担当の一致 → completed → 同一値・遷移/候補条件の順に判定します。完了後はrequest_completed、古い版はstale_version、同一担当（停止済みも含む）/同一状態はno_change、禁止遷移はinvalid_transition、未割当・停止担当で状態変更/非open解除はinvalid_assignee_stateです（いずれも409）。別の社員/不存在/停止候補は422。lock中の停止との衝突は503で、停止が確定した候補の422と区別します。version最大値からの更新は500として無変更を保ち、整数を巻き戻しません。

全応答はprivate, no-store、422だけfieldsを返し、409に最新の依頼や担当情報を混ぜません。クライアントは認可された詳細GETでversionを再取得して操作を選び直し、自動再送しません。サーバー側DB transactionの再試行回数も増やしません。HTTP断で結果不明の場合はcommit済みの可能性を残し、成功/失敗を推測しません。

## FR・ACと検証の対応

| 今回の対象 | API・データ | 主な検証 |
| --- | --- | --- |
| FR-06 / AC-10 | API-08/09、users / service_requests | 有効ITのみ候補、最小2項目、20/21件・ID順・範囲外。XがYを割り当て、担当外Yが変更、open解除、同一409、不正候補422 |
| FR-07 / AC-11 | API-10、同上 | 4×4の16組を検査。許可4遷移のみ成功、担当保持、未割当/停止409、有効担当へ変更後に再開、非open解除/完了後変更拒否 |
| AC-07/08/09 | 3 API、既存認証・scope・Policy | 未認証/停止/auth_version/期限切れ拒否、社員の本人依頼403、他人/不存在同じ404、保護項目/所有者の不変 |
| AC-14（担当/状態） | API-09/10、users共有lock・親排他lock・version | 異なるIT・Cookie・HTTPプロセス/DB接続。担当同士・状態同士・相互で同じ版から更新、一方200/一方409。最新を推測した版でも事前担当が変化した場合409 |
| AC-18、共通入力 | PATCH、既存CSRF/ApiBoundary | CSRFなし/不一致419、正常値成功、未知項目422、JSON重複400、別形式415、過大413、入力型/版境界 |
| 原子性・停止競合 | 親更新 → session保存 → commit | session書込trigger失敗503、UPDATE後非DB例外500、担当/状態/version/updated_at全て不変。候補停止先行/割り当て先行の両順序 |
| AC-15（維持） | 既存Requests suite | 新APIの未提供確認を認可検査へ変更。依頼編集/削除、コメント等の未提供404/405を維持 |

並行試験はUPDATE直後・外側commit前にファイル同期点を置きます。別接続から未commitの業務変更が見えないこと、第二HTTP workerがpg_stat_activityで親/ユーザー行lock待ちになることを確認してから解放します。sleepは短い観測間隔だけに使い、待った時間で順序を推測しません。停止先行では停止UPDATE後の同期点で保持中を確定し、NOWAITの503を確認します。同期点・障害注入は接続先ガードを通すtests/http-router.phpだけで、本番entrypointは読みません。

実行結果・Windows再実行・データ保全は [development.md](development.md)の09-28記録を参照してください。既存users/認証/依頼も全体再実行し、GitHub上の実行・必須チェック設定とは分けます。コメント投稿対完了、画面、公開デモ投入、AWS、実HTTPS/通信断/公開worker終了・負荷は未検証です。
