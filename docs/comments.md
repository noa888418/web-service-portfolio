# コメント閲覧・投稿と完了処理の整合性

## 画面接続の追加（2026-09-30）

SCR-04にAPI-11の一覧・API-12の投稿を接続しました。本文だけを送信し、親version/updated_atは変更しません。201後の表示更新失敗とPOST結果不明を区別し、後者はGETで同文を見つけても再投稿を自動許可しません。完了409の下書き保持・投稿停止、認証/閲覧権失効時の消去を実装しました。[画面仕様](screens.md)、[FR/AC対応・検証結果・Windows手順](frontend.md)を参照してください。

隔離実ブラウザーの「投稿成功後に完了」「完了応答後に古い投稿が409」は順序を制御した画面試験です。以下の別HTTPプロセス/DB接続を同時に動かすバックエンド試験を置き換えません。以下はバックエンド工程時点の記録で、当時のReact未実装・XSS未検証と今回の画面検証を区別します。API・migration・lockの変更はありません。

## 採用範囲（2026-09-28）

FR-05のコメント閲覧、FR-08のコメント投稿、AC-14の投稿と完了の競合防止を採用しました。社員は自分の依頼、IT担当者は全依頼で閲覧・未完了時の投稿ができます。完了後も閲覧可、投稿は409です。投稿者はサーバーで認証中の本人から設定します。本文はプレーンテキスト、前後Unicode空白除去・改行LF統一後1～2,000コードポイント。20件・created_at ASC, id ASC、親のversion/updated_atは投稿で変更しません。expected_versionは不要で、送った場合は未知の入力として422です。

コメント編集/削除・内部限定コメントは提供しません。React・公開デモseed・Terraform・AWSは今回も対象外です。APIとしての文字列保存/返却と、ブラウザーでのXSS非実行は区別します。

開始時HEADは `62c0833a61f124f84190c2eeff0bf366abd1a3fb`、未コミット差分はありませんでした。前工程の193テスト・2,707アサーション・終了コード0は利用者のWindowsでも確認済みです。これはGitHub CIの証跡ではなく、今回も対象コミット・実行URL・結果は未確認です。commit・pushは行いません。

## 実装単位とDB保証

- [追加migration](../backend/database/migrations/2026_09_28_000001_create_comments_table.php)：commentsのidentity正数PK、親FK（DELETE CASCADE）、投稿者FK（DELETE RESTRICT）、全列NOT NULL、本文char_length 1～2000、created_at timestamptz(6)。親/日時/IDと投稿者のindexを追加。既存3本のmigrationは変更しません。
- [Commentモデル](../backend/app/Models/Comment.php)：mass assignment禁止、ID文字列、created_atのみ。親へのtouch・updated_at列を追加しません。
- [CommentController](../backend/app/Http/CommentController.php)：専用GET/POSTのみ。既存RequestText、AuthInput、ServiceRequest.visibleTo、認証/CSRFと外側transactionを再利用します。
- [Commentsテスト](../backend/tests/Comments/CommentApiTest.php)、[DB制約テスト](../backend/tests/Comments/CommentSchemaTest.php)：実PostgreSQL、実Cookie・別HTTP workerを使用。CIにComments suiteと全体再実行を組み込みます。

DBは親/投稿者の存在、必須・長さを保証し、Unicode trim・投稿者の閲覧権・親の未完了を自動判定しません。これらはLaravelで検査します。親削除CASCADEは運用での削除用で、Webの削除APIではありません。公開DB roleのUPDATE/DELETE制限は未実装です。現在のローカルDB所有roleで直接SQLが可能なことを、アプリの認可と混同しません。

## 認可・一覧・transaction

API-11 `GET /api/requests/{request_id}/comments`、API-12 `POST /api/requests/{request_id}/comments`は既存web / CSRF / AuthenticatedSessionに置きます。未認証・停止・期限・auth_versionの照合やloginの試行制限を省略しません。

共通判定は認証 → 親の閲覧範囲 → 保護項目/入力 → lock後の再確認・状態です。他人の親と不存在・不正path IDは同じ404。author_id / service_request_id / requester_id / role等は403、未知の入力・expected_versionは422、CSRF不足/不一致は419、completed投稿は409 request_completed。変更系でCSRFが先に拒否する場合は401より419が先です。全応答はprivate, no-store、エラーへ本文・SQL・認証情報を反射しません。

GETでは先にスコープ付き親で認可し、入力検査後、再度スコープ付き親CTEとコメント/投稿者CTEを含む**単一SELECT**で親の存在・件数・20件を取得します。READ COMMITTEDの同じsnapshot内で不可/不存在なら404、可だが0件なら200空配列となり、metaから情報が漏れません。transaction途中で分離レベルを変更しません。範囲外ページは200空配列、last_page最小1です。ページ間に追記された場合のずれは既存設計どおり許容します。依頼詳細へ全コメントを埋め込みません。

POSTの実際の順序：

1. 外側transaction → session advisory lock → session読取/CSRF → 本人users FOR SHARE、停止・期限・auth_version照合。
2. 閲覧scope・許可入力・本文の正規化/長さを確認。
3. **親依頼1行をFOR UPDATE**。待機後の最新行で閲覧権・未完了を再確認。lock前の状態だけでINSERTしない。
4. 本人IDとURLの親IDでコメントINSERT、Comment JSONを作成。親のsave・version比較/加算・timestamp更新はしない。
5. **session保存 → 外側commit → 201送信**。途中例外、5xxへの変換、session保存失敗では既存LockedSessionがコメントもrollbackする。

追加の担当候補users lockは不要です。本人共有lockは取得済みで、親の後に別users lockを追加しません。完了PATCHの「本人共有lock → 追加担当users共有lock NOWAIT → 親FOR UPDATE」と同じ親の排他lockで競合します。投稿者が現担当者の場合も共有lock同士は両立します。業務4xxはINSERT前に決定し、認証の失効記録等まで一律rollbackする変更はしません。

POST/通信失敗を自動再送する仕組みは追加しません。結果不明なら認可されたコメントGETで確認し、画面でどう案内するかは画面工程です。SQL lock_timeout=5秒 / statement_timeout=10秒は維持し、要求全体や通信断の上限を保証するものではありません。

## FR・AC・検証の対応

| 対象 | API・データ | 実PostgreSQL/実Cookie HTTPの検証 |
| --- | --- | --- |
| FR-05 / AC-06・08 | API-11、親・comments・users | 本人/IT閲覧、別社員の一覧/件数404、不存在同文面、0/20/21件、日時優先/同時刻ID昇順、範囲外・page不正 |
| FR-08 / AC-05・12・13 | API-12、comments | 本人/IT投稿、1/2000/2001コードポイント・日本語/絵文字、空白/型/NUL、改行正規化、タグ風本文が文字列のまま往復、最小Comment JSON |
| AC-07・09・18 | 共通認証/CSRF・AuthInput | 未認証/停止/auth_version/無操作/絶対期限、CSRF不正、親/投稿者の改ざん拒否、業務データ不変 |
| AC-12・14 | 投稿POSTと完了PATCH、親FOR UPDATE | 投稿先行は201後に完了200・コメント残存。完了先行は200後に投稿409・コメント0。異なるセッションの2投稿は両方201・親全列不変 |
| AC-15 | 未提供method/子パス | 編集/削除404/405、既存コメント・親不変。旧コメント未提供テストを置換し拒否確認を維持 |
| DB・原子性 | 新migration・既存LockedSession | 直接SQLのFK/NOT NULL/CHECK、親CASCADE/投稿者RESTRICT、既存行を保持する追加migration、session保存/INSERT後例外のrollback |

並行テストは社員AとIT担当者X、異なるsession Cookie、別PHP HTTPプロセス・DB接続を使います。先行INSERT/完了UPDATE直後（親lock保持・commit前）で同期ファイルを待ち、第二要求がpg_stat_activityで親のFOR UPDATE待ちに入ったことを観測してから解放します。別接続で先行の未commit変更が見えないことも検査します。sleepは短い観測間隔だけで、長さから順序を推測しません。2コメントでは先行commit後に後続も保存され、不要な版競合がないことを確認します。

障害注入・同期点はDB接続先/schemaガード済みのtests/http-router.phpだけにあり、本番entrypointは読みません。テストfixtureは公開配布用資格情報ではありません。実行結果・Windows手順・データ保全は [development.md](development.md)のコメント工程記録を参照してください。

未検証：GitHub上の追加CI/必須チェック設定、ReactのXSS非実行・二重操作・通信結果不明の表示、公開デモseed・AWS/HTTPS/CloudFront、公開DB権限分離・負荷・worker終了/通信断のtimeout。
