# 依頼登録・一覧・コメントを除く詳細の実装

2026-09-29追記：下記API契約を変更せず、社員登録（SCR-03）と詳細閲覧（SCR-04の本文部分）をReactへ接続しました。モックの入力/認証/通信失敗と、隔離実ブラウザーの登録・A/B/IT閲覧・HTML風本文非実行を[画面検証記録](frontend.md)に分けています。開発DBへのテスト登録は行わず、担当/状態/コメント画面は次工程です。

最新のコメント閲覧/投稿は [comments.md](comments.md)で採用・実装しています。API-07の詳細には全コメントを埋め込まず、API-11で取得します。以下の過去の未提供記録は当時の状態です。

この文書は2026-09-27の登録/閲覧工程の記録です。09-28に担当/状態更新を追加採用・実装しました。[request-workflow.md](request-workflow.md)を参照してください。以下の「今回」「未実施」は当時の範囲で、最新状態・検証件数は [開発記録](development.md)に分けています。155テスト・1,656アサーションは利用者のWindowsでも確認済みです。

## 採用範囲と作業基準

2026-09-27、FR-03・FR-04とFR-05のコメントを除く詳細を今回の実装前提として採用しました。社員のみ登録でき、依頼者は認証中の本人、初期状態はopen・担当なし・version=1です。社員は本人の依頼だけ、IT担当者は全依頼を閲覧できます。他人の依頼と不存在は同じ404です。既存のタイトル100文字・本文5000文字・3種別、20件の固定順ページングを採用しました。

担当変更・状態変更・コメント、React画面・AWS・公開デモseedは実装しません。FR-05全体の完了ではありません。将来の担当・状態用カラムとDB制約を用意したことを、操作仕様の承認・実装に広げません。

開始時のHEADは `062ee5901e8f0331d63b909903da19d1471b8066`、未コミット差分はありませんでした。このSHAはローカル作業基準であり、GitHub CIの実行証跡ではありません。利用者から83テスト・645アサーション・終了コード0のローカル再実行成功を確認済みです。対応するGitHub実行URL・結果の証跡は得ておらず、今回のCI成功とは記録しません。

## 実装と責務

- [migration](../backend/database/migrations/2026_09_27_000002_create_service_requests_table.php)：identity / timestamptz(6)、必須・長さ・列挙・正のversion、依頼者FK、担当者のIT複合FK・担当必須CHECK、一覧用index。通常の差分migrationで、usersや既存データを初期化しません。
- [ServiceRequest](../backend/app/Models/ServiceRequest.php)：全mass assignment拒否、ID文字列、日時・versionのcast、`visibleTo`スコープ。停止者や未知の役割は空範囲とします。認証middlewareの照合を省略する代替ではありません。
- [ServiceRequestPolicy](../backend/app/Policies/ServiceRequestPolicy.php)：有効な社員のみ登録許可。閲覧は全件queryの後で除外するのでなく、モデルのスコープでDB検索・件数の前に制限します。
- [ServiceRequestController](../backend/app/Http/ServiceRequestController.php)：明示した入力だけを読み、依頼者・初期状態をサーバー設定。最小のRequestSummary / RequestDetailを返し、内部補助列・メール・認証情報は含めません。
- [RequestText](../backend/app/Support/RequestText.php)：Unicode空白trim、CRLF / CRをLFへ統一、コードポイント数、NUL / 不正UTF-8拒否。タイトルのCR/LFはtrim前にも拒否し、前後の改行で禁止条件を回避しません。種別は列挙値の完全一致です。
- 本文はプレーンテキストのJSON文字列として保存・返却します。タグをHTMLへ変換したりMarkdownを解釈したりしません。Reactでのエスケープ表示とブラウザーでのXSS検証は次工程です。

認証・CSRF・期限・停止・auth_versionは既存の [認証middleware](../backend/app/Http/Middleware/AuthenticatedSession.php) を全3 APIに適用します。Cookieやrequest bodyから利用者・役割を独自に決めず、更新直前まで共有lockされたusersを認可の根拠にします。loginの試行制限も維持します。

## トランザクションの調整と実際のcommit

既存のLockedSessionは、セッション読取前にtransactionを開始し、advisory lock → session読取 → CSRF → users FOR SHARE → controller → session保存 → commitの順で動きます。今回の登録はこの**同じ接続・外側transaction**内でINSERTします。controller内で独立commitしたようには扱わず、JSONを組み立てた後にsession保存と外側commitが成功して初めて201を送信します。通信失敗時にPOSTを自動再送しません。

停止側はusers FOR UPDATEを必要とするため、先行する登録とsession保存のcommitを待ち、その後に停止・auth_version加算・session削除をcommitします。停止が先にcommitされれば認証時点で登録を拒否します。実HTTPの別プロセスでINSERT後に同期点を置き、別接続から依頼がまだ見えないこと、停止がusers lock待ちになること、解放後に登録・停止が順に確定することを検査します。

Laravelは途中の例外をroute pipeline内で500応答へ変換する場合があります。そのまま外側transactionを終了すると部分登録をcommitする危険があるため、[LockedSession](../backend/app/Http/Middleware/LockedSession.php) は5xx応答を内部例外へ変えてrollback後に返します。DB・session保存例外も外側でrollbackします。認証失敗や試行制限counterの必要な更新を壊さないよう、4xx全部を一律rollbackにはしていません。今回の業務4xxはすべてINSERTより前に決定します。

### 一覧の件数と行を揃える方法

旧設計はREAD ONLY / REPEATABLE READでCOUNTと行を取得する案でした。しかし認証が既にDBを読み、usersを共有lockし、最後にはsessionを書きます。このtransaction途中で分離レベルを変更したりREAD ONLYにしたりすることは適合しません。

そこで一覧は認可スコープ付きCTEから、COUNTと20件のページ行を**単一SELECT**で取得します。READ COMMITTEDでもこのSELECT内は同一snapshotとなり、範囲外・0件でも件数を返せます。詳細もusers表示名をJOINした単一SELECTです。別接続・別snapshotでCOUNTする方式や、セッション全体をREPEATABLE READへ変更する方式は採用しません。別ページ間の新規登録によるずれは元の設計どおり許容します。

公式根拠（2026-09-27確認）：[PostgreSQL 18のREAD COMMITTED](https://www.postgresql.org/docs/18/transaction-iso.html#XACT-READ-COMMITTED)、[SET TRANSACTIONの制約](https://www.postgresql.org/docs/18/sql-set-transaction.html)。小規模デモでは認可範囲のCTEをmaterializeする単純さを優先し、大量データの性能は未測定です。将来は件数・実行計画を確認して見直します。

## 要件・API・実装・検証の対応

| 要件 / 受入条件の今回部分 | API / 主な実装 | 実PostgreSQL・Cookie HTTPの検証 |
| --- | --- | --- |
| FR-03 / AC-04 | API-06 POST /api/requests、Policy・controller・users / service_requests | A登録201、本人・open・NULL・version1、X/Y登録403・件数不変 |
| FR-03 / AC-05 | API-06、RequestText・DB CHECK | 必須・型・空白・NUL・日本語/絵文字の1/100/5000境界、超過・3種別・不正値 |
| FR-04 / AC-06・08 | API-05 GET /api/requests、visibleTo・単一SELECT | A/Bの件数/行を分離、X/Y全件、0/20/21件・日付優先/同時刻ID降順・範囲外 |
| FR-05（コメント除外）/ AC-06・08 | API-07 GET /api/requests/{request_id}、同じvisibleTo | 本人/IT可、他人と不存在が同じ404、不正・bigint超過IDも404 |
| AC-07・認証方針 | 共通AuthenticatedSession・Sanctum | 未認証・停止・auth_version更新・期限切れで3 API拒否。既存認証suiteも継続 |
| AC-09 | AuthInput・登録Policy | body/queryの保護項目403、一般未知項目422、所有者・役割・状態の不変 |
| AC-13のAPI部分 | RequestText・JSON応答 | タグ風文字列を文字列のまま保存・返却。画面での非実行は未検証 |
| AC-15の今回部分 | 登録/一覧/詳細・担当/状態・コメントルート | 依頼編集/削除の405を維持。コメントGETの旧404確認を、コメント編集/削除method405・子パス404へ置換。新規の閲覧/投稿認可はComments suiteで検査 |
| AC-18の登録部分 | 既存VerifyCsrf | CSRFなし/不一致419・依頼0、正しいCSRFで201 |
| 整合性・データ保全 | LockedSession・既存DB接続先ガード | session保存失敗503、INSERT後の例外500で依頼0、停止とのcommit順、専用schemaのみで実行 |

[RequestApiTest](../backend/tests/Requests/RequestApiTest.php) と [RequestSchemaTest](../backend/tests/Requests/RequestSchemaTest.php) をRequests suiteへ追加しました。直接SQLでもNOT NULL / CHECK / FK / RESTRICTが働くことを検査します。DBは依頼者が社員であること、trim、操作権限・遷移順までは保証せず、役割Policyや入力検査と区別します。

HTTP検証は既存CookieBrowser・2プロセスのHTTP基盤を利用します。障害注入とINSERT後の同期点は専用DBガード済みのtests/http-router.phpだけにあり、public/index.phpは読みません。テスト用資格情報は一時schemaの架空fixtureで、公開デモの配布用資格情報ではありません。

## 実行・未実施の境界

Windows手順とテスト件数・結果は [development.md](development.md)に記録します。既存workflowにRequests suiteを追加し、Gitleaks・既存check名・contents:readを維持します。ローカル成功、GitHub実行、必須チェック設定は別項目です。

未実施：FR-05のコメント、担当・状態変更、画面のXSS/操作性、公開デモseed、AWS/CloudFrontのAC-19、公開Web DB roleの最小権限、大量データの性能、ネットワーク断と公開worker終了。今回の検証をこれらの成功へ広げません。
