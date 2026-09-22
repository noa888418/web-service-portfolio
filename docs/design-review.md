# DB・API・画面の横断設計レビュー

2026-09-22。机上の対応確認と検証計画に、users実装の結果を追記しました。**usersの保存設計だけを採用し、実PostgreSQLの制約テストを実施済み**です。認証・API・画面・依頼・コメント・AWSは未実装 / 未検証で、業務詳細は引き続き提案です。[要件](requirements.md)、[DB](database.md)、[API](api.md)、[画面](screens.md)、[セキュリティ](security.md)を参照してください。

## FR → 画面 → API / 管理手順 → テーブル

| FR | 画面 | API / 手順 | 主なテーブル・保証箇所 |
| --- | --- | --- | --- |
| FR-01 | SCR-01、共通認証 | API-01・API-02・API-04 | users・sessions・cache・cache_locks。ハッシュ / 停止 / auth_version / CSRF・期限・試行制限 |
| FR-02 | SCR-02～SCR-04 共通ヘッダー | API-03・API-04 | sessions・users、cache_locks。現 session 失効、旧 Cookie 拒否 |
| FR-03 | SCR-03 | API-06 | users・service_requests。社員のみ・所有者サーバー設定・CHECK |
| FR-04 | SCR-02 | API-05 | service_requests・users。本人 scope と件数、20 件降順 |
| FR-05 | SCR-04 | API-07・API-11 | service_requests・users・comments。親の認可、本文 / コメント、20 件昇順 |
| FR-06 | SCR-04 IT操作欄 | API-08・API-09 | users・service_requests。IT 判定、複合 FK、version、親行ロック |
| FR-07 | SCR-04 IT操作欄 | API-10 | users・service_requests。遷移表・担当必須 CHECK・version・親行ロック |
| FR-08 | SCR-04 投稿欄 | API-12 | users・service_requests・comments。親ロック内で閲覧 / 状態検査 |
| FR-09 | 画面なし | OPS-02・OPS-03（OPS-05 から利用） | users・service_requests・comments・demo_seed_runs。用途 / 対象 / 許可、空 DB、原子的投入と同一再実行 no-op |

保護 API では上記の業務テーブルに加え users / sessions / cache_locks を使います。migrations は全 schema の適用管理、cache は試行制限であり、業務機能を増やすものではありません。

## AC → 画面 → API / 管理手順 → データと期待結果

| AC | 画面 | API / 手順 | データ・机上で確認した設計 |
| --- | --- | --- | --- |
| AC-01 | SCR-01・SCR-02 | API-01・API-02・API-04 | users / sessions。2 役割がログインし本人だけの最小項目を返す |
| AC-02 | SCR-01 | API-02 | users / cache。不存在・停止・誤資格情報は同じ 401。制限超過は 429 |
| AC-03 | 共通ヘッダー・SCR-01 | API-03・API-04、保護 API | sessions。旧 Cookie の GET は401、変更は401/419。並行 session 保存を直列化 |
| AC-04 | SCR-03・SCR-04 | API-06・API-07 | service_requests。A の依頼作成は201、ITの作成は403、初期値はserver設定 |
| AC-05 | SCR-01・SCR-03・SCR-04 | API-02・API-06・API-12 | users / service_requests / comments。Unicode長・必須・型・境界値422、password未加工 |
| AC-06 | SCR-02・SCR-04 | API-05・API-07・API-11 | users / service_requests / comments の認可scopeと件数。0 / 20 / 21件・固定順 |
| AC-07 | 保護3画面 | API-04～API-12 | 未認証に業務情報を返さず、GET401 / 変更401または419。業務 DB 不変 |
| AC-08 | SCR-02・SCR-04 | API-05・API-07～API-12 | 不可の親IDと不在は同じ404。本文の保護項目は認可順に403（下記修正案） |
| AC-09 | SCR-01・SCR-03・SCR-04 | API-02・API-06・API-09・API-10・API-12 | role / requester_id / author_id 等を拒否。社員の自分の対象の担当・状態変更は403 |
| AC-10 | SCR-04 | API-08・API-09 | 有効ITへの割当・変更、openで解除。非IT/不在/停止候補422、禁止解除/完了409 |
| AC-11 | SCR-04 | API-10 | 許可4遷移のみ。未割当 / 同一 / 飛越 / 再開409、担当保持 |
| AC-12 | SCR-04 | API-12・API-11 | A・X・Yは投稿可、親completedなら409。author_idは本人から生成 |
| AC-13 | SCR-03・SCR-04 | API-06・API-07・API-11・API-12 | body / title をテキスト描画、パラメーター化query、HTML非実行 |
| AC-14 | SCR-04 | API-09・API-10・API-12 | version競合409、users→親ロック、完了commit後のコメント拒否 |
| AC-15 | SCR-03・SCR-04・直接要求 | 未提供の PUT / DELETE / user更新等 | users / service_requests / comments 不変。404/405、protected追加403 |
| AC-16 | 画面なし（投入後 SCR-01～SCR-04 で実演） | OPS-02・OPS-03 | 業務3表 + demo_seed_runs。productionで許可済みdemo成功、同じ再実行no-op |
| AC-17 | 画面なし | OPS-02・OPS-03 の前提検査 | 非demo・未許可DB・許可なしは非0、全表と資格情報不変 |
| AC-18 | SCR-01・SCR-03・SCR-04・共通logout | API-02・API-03・API-06・API-09・API-10・API-12 | CSRF不足/不正419、業務 DB 不変。正しい値で認可試験へ進む |
| AC-19 | SCR-01・SCR-02・SCR-04 | API-01～API-12 の応答、特に認証/GET | CloudFront経由の本文 / Set-Cookie非混在・no-store。AWS実機で別途検証 |

実装時のテーブル名 / JSON キーは各設計書の正式名に統一します。

## A・B・X・Y による操作の机上追跡

ID 例は A=1、B=2、X=3、Y=4。以下は実行結果ではなく、設計に従った期待結果です。CSRF・期限・試行制限枠を整えて業務認可を検査します。

| ケース | 手順と期待結果 |
| --- | --- |
| 一連の正常系 | AがSCR-03から依頼101を作成→open / version1 / 担当なし。XがYを割当→version2、in_progressへ→3、waiting_confirmationへ→4。Aがコメント→親version4のまま。Xがcompletedへ→5。A/Bの一覧は自分の依頼だけ、X/Yは全件 |
| 他人の ID | Bが/api/requests/101やそのcommentsへGET/POST→404。同じ応答を不存在IDでも返す。所有者query追加で他人一覧を取得できず403。自分の親へauthor_id=1を追加→403 |
| 保護項目 | Aが自分の依頼で担当・状態をPATCH→403。Aが新規登録にstatusやrequester_id、loginにroleを追加→403。Xでも汎用users更新は未提供で404/405 |
| 状態と担当 | Xがopen未割当にin_progress→409。候補が社員Bなら422。in_progressの解除・completed後の割当/投稿/再開→409。DB直接SQLの非IT担当・担当なし対応中も制約違反 |
| 同時更新 | X/Yがversion4を読み、同時にstatus変更。一方だけcommitしversion5、後続は409。画面はGETで5を取得し自動再適用しない |
| コメントが先 | Aが親101をロックしてINSERT・commit、Xの完了処理はその後commit。完了後の履歴にそのコメントが残る |
| 完了が先 | Xが親101をロックし完了commit。待っていたAは最新completedを読み409、コメントは増えない |
| CSRF / 期限 | AのCookieありでもCSRF欠落・不一致→419。正しいCSRFで別途権限を検査。停止・期限切れは401、変更は先行CSRF検証で419もあり |
| 通信結果不明 | AのPOST後に応答だけ喪失しても再送しない。一覧/コメントGETで確認、判断不能なら作成者確認。二重登録ゼロを未実装のidempotency機構で保証したことにしない |
| 公開終了 / 次回 | 作成者が閉鎖、drain、利用者停止・全session失効、資格情報rotation、snapshot取得、撤去。次回は新DB + 新資格情報。旧Cookieや旧配布パスワードを使わない |

## 既存要件との調整記録（提案）

黙って意味を変えず、次の修正・補足を要件書にも記録します。業務詳細の承認は引き続きレビュー対象です。

| 対象 | 元の曖昧さ / 矛盾 | 今回の修正案と理由 |
| --- | --- | --- |
| AC-08 / AC-09 | 本文中の他人IDも一律404と読める一方、保護項目は403 | 親URLが不可/不在なら先に404。閲覧可の親へのauthor_id等の追加は403。単独comment ID APIは作らず未提供404。入力から他人のレコードを検索して情報を返さない |
| AC-03 / AC-07 / AC-18 | logout後のすべての再要求が401と読める | GET401、変更は401/419。未認証の再logoutは正しいCSRFなら204、不正なら419。CSRF検査を回避して204を保証しない |
| AC-16 | 空DBが必要なのに再実行でも初期投入と読める | 初回は空DB・成功記録なし、同じ投入済み記録はno-op、非空で記録なしは拒否。既存のユーザー操作や資格情報を上書きしない |
| FR-06 / AC-10 | 「IT担当者」だけでは停止者の扱いがない | 新規候補は有効ITのみ。停止者は422、既存担当が停止なら再割当まで状態変更409。過去の表示は維持 |
| FR-06・FR-07 / AC-14 | 「版など」の方式が未定 | 担当・状態のみversion整数を増やす。コメントは親ロックだけで追記。不要なコメント同士の競合を増やさない |
| FR-01 | 入力128文字とハッシュ方式、期限・試行制限が未定 | Argon2id案、無操作30分・絶対8時間、メール5回/分・IP30回/分を提案。bcryptへの黙った切詰めを防ぎ、小さなデモに合わせる |
| 保存・復元 | 次回の引継ぎ/保持期間/責任者が未定、snapshot30日案 | 今回の指示に従い次回初期化・7日/通常1世代・作成者責任へ更新。7日期限と復元検証の一時併存を両立し、費用枠は据置 |
| 内部HTTP・TLS | 許容が未定 | 架空データ専用デモに限る例外を前提化。理由・残存リスク・見直し条件は基本設計に記録。実業務向けの安全性を主張しない |

## 検証の境界と予定

| 区分 | 検証内容 | 今回の状態 |
| --- | --- | --- |
| 文書 | リンク、FR9件/AC19件の対応、API12件/画面4件/OPS7件、コード・権限・全遷移、例のJSON、既存差分・秘密情報検査 | 今回の確認対象。実行結果は作業報告に記録 |
| users / 実PostgreSQL | 空schemaへのmigration、役割2種、正規化・一意性、直接INSERTのCHECK / NOT NULL、既定値・停止状態、UNIQUE(id, role)参照、JSON非公開、接続先ガードと再実行の保全 | 62テスト・101アサーション成功。[開発記録](development.md)。FR-01 / FR-09の保存基盤のみ、AC全体の合格ではない |
| Laravel / React | Policy全組合せ、保護項目、入力境界、401/403/404/409/419/422/429、期限・停止、error整形、二重操作、画面遷移・keyboard / focus / XSS | 未実施、アプリ実装後 |
| 実 PostgreSQL（残り） | 同時INSERTのメール競合、依頼・コメントの複合FK / CHECK / NULL・子削除、同一snapshotの件数、2接続のversion更新・投稿対完了・利用者停止、deadlock/timeout rollback、cache原子性・session同時保存、seed同時実行/途中失敗/no-op | 未実施。採用版 PostgreSQL で別接続・同期点を使い両順序を確かめる。sleep頼み・SQLite代替にしない |
| ローカル結合 | 同一オリジンCookie / CSRF / logout、複数ブラウザーA/B、API非キャッシュヘッダー、通信切断で結果不明時の非再送 | 未実施、アプリと開発環境が必要 |
| AWS 実機 | CloudFront標準TLSとorigin経路、Cookie/Header転送、A/Bのcache混在なし、SG直アクセス拒否、proxy/IP/HTTPS判定、RDS TLS、OIDC、秘密注入、snapshot復元と7日削除、閉鎖・失効・課金対象撤去、メモリ/ハッシュ負荷 | 未実施。ローカルDBテストでは代替できない |

AC-19 は Laravel の no-store 単体確認だけで合格にしません。Actions の初回 push は利用者確認済みですが、PR・CI検出時の失敗・必須チェックは引き続き未確認です。今回の設計追加を既存CIが検証したとは記載しません。

## 重要な未決定事項と最初の実装単位

1. **業務案の採否**：社員だけの登録、IT全員の担当外操作・完了判断、編集/削除/再開なし、入力上限をレビューする。
2. **認証と公開の詳細**：提案した期限・試行制限値・資格情報の配布経路、公開用の対応版、単一taskの性能を確定・検証する。ローカルのPHP / Laravel / PostgreSQL / PHPUnitは選定済み。
3. **リリース条件**：依存関係/イメージ/IaC検査のツールと停止基準、必要なCI必須チェック、予算通知先を決める。

最初の小さな実装単位である **users migration・正規化・役割・停止の保存検証**は実施済みです。レビュー単位は①Docker / Laravel / 専用DBガード、②users / メール処理 / PHPUnit、③users CI / 文書とします。DB管理表migrationsの標準構造との差と、認証モデルをまだ導入しない理由は [DB文書](database.md)に記録しました。次は期限・失効・試行制限の案を確認し、FR-01 / FR-02 のCookieログイン・me・logoutへ進みます。
