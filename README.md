# 転職用 Web サービスポートフォリオ

小規模な Web サービスを開発し、要件整理・設計・実装・テスト・運用の考え方を示すプロジェクトです。成果物に加えて、技術の選定理由、設計上のトレードオフ、検証方法と結果を自分の言葉で面接で説明できることを目的とします。

題材は **「社内IT依頼・改善管理サービス」** に決定しました。単一会社の社員と IT担当者が、依頼登録・担当割り当て・状態変更・コメントで受付から完了までを管理する初期版です。段階ごとに採用した範囲と、画面・公開運用等のレビュー対象の案を [要件](docs/requirements.md)で区別しています。

## 現在の状態と今回の範囲

**FR-09のガード付きローカル初期投入と、通常のpublic/index.phpを使うHTTP起動を追加しました。** 社員A/B・IT担当者X/Yと架空依頼・コメントを、空の許可DBへ明示コマンドで投入します。同じ再実行はno-opで既存データ・資格情報を変更しません。[Windowsでの準備・投入・接続・保持した停止](docs/demo.md)を参照してください。React・AWSへの投入・資格情報の外部配布は未実施です。

前工程の**236テスト・3,488アサーション・終了コード0は利用者のWindowsでも確認済み**です。追加後はこの作業環境で**270テスト・3,855アサーション・終了コード0**を確認しました。開発DBは投入前にusers 0件を確認し、追加migration後に4利用者・4依頼・4コメント・成功記録1件を投入しました。以下の既存工程の記録と、[最新検証記録](docs/development.md)を区別します。GitHub CIの今回実行は未確認です。

初期ドキュメントに加え、秘密情報の混入を防ぐ `.gitignore`、Gitleaks のコミット前検査、GitHub Actions の秘密情報検査を整備しました。この作業環境ではローカルフックの登録と隔離リポジトリでの検証が完了しています。GitHub Actions の初回 push に対する検査成功は **利用者確認済み** です。実行 URL・対象 SHA・詳細ログは未提示で、こちらでの直接確認はしていません。PR の検査、CI での検出時の失敗、必須チェック設定は別途確認が必要です。

users・認証・依頼・担当/状態に続き、**FR-05のコメント閲覧、FR-08のコメント投稿、AC-14の投稿対完了の競合防止**を実装しました。投稿は親依頼をロックして未完了を再検査し、親のversion/updated_atは変更しません。React・公開デモseed・Terraform・AWSは未実装です。[コメントの実装と検証](docs/comments.md)、[担当/状態](docs/request-workflow.md)、[依頼API](docs/requests.md)、[認証](docs/authentication.md)を参照してください。

DB・API12件・共通4画面の [横断対応表](docs/design-review.md)があります。前工程のusers 62テスト・101アサーション・終了コード0は**利用者のWindowsでも確認済み**です。今回は実Cookie・HTTP・PostgreSQLによる停止者拒否、失効、CSRF、期限、試行制限、並行処理を追加検証しました。既存workflowも認証テストを含めましたが、**今回のGitHub上の実行成功は未確認**、必須チェックも未確認です。

認証工程の**83テスト・645アサーション・終了コード0は利用者もローカル再実行で確認済み**です（Users 62 / 101、Authentication 21 / 544）。依頼API追加後の結果と [再実行手順・検証記録](docs/development.md)は別に記載しています。GitHub CIの対象コミット・実行URL・成功証跡は未確認です。

担当・状態更新までの**193テスト・2,707アサーション、終了コード0は利用者のWindowsでも確認済み**です。コメント工程では**236テスト・3,488アサーション、終了コード0**を確認しました。[検証記録](docs/development.md)へ工程ごとに記録しています。コメント工程当時は開発DBを変更せず、既存migrationを維持してcommentsを追加しました。今回のFR-09工程の開発DB適用・投入は冒頭の記録を参照してください。

AWS 基本設計と費用を公式仕様に照らして文書化しました。AWS リソース作成・実機検証は行っていません。構築・検証・撤去を含む月 60 時間の提案条件では約 2,134 円（税込、1 USD = 160 円の仮定）ですが、期間外の ALB・ECS・RDS 撤去が前提です。詳しくは [費用見積もり](docs/costs.md)を参照してください。

## 開発開始前の秘密情報検査

Windows PowerShell で、リポジトリのルートから実行します。Python 3.10 以上と Git が必要です。

```powershell
python scripts/secrets.py install
python scripts/secrets.py install-hook
python scripts/secrets.py files
```

各コマンドが成功したことを確認して次へ進みます。フックはクローンごとに登録が必要です。ローカルと CI は `config/gitleaks.json` の Gitleaks 8.30.1 を使用し、検出・実行エラー時には失敗します。手動でステージ内容を確認する場合は `python scripts/secrets.py staged` を実行します。

詳しい Windows セットアップ、既存フックとの共存、誤検知の確認方法は [開発環境の手順](docs/development.md)を参照してください。

## 決定済みの技術構成

| 項目 | 方針 |
| --- | --- |
| フロントエンド | React + TypeScript + Vite |
| バックエンド | Laravel 13.32.0 / PHP 8.4.25 / Composer 2.10.3（ローカル採用版） |
| DB | PostgreSQL 18.6（ローカル。RDS の採用版は別途確認） |
| 開発環境 | Docker |
| 本番環境 | AWS ECS（Fargate と周辺構成は基本設計案） |
| インフラ構築 | Terraform |
| CI/CD | GitHub Actions |
| 自動テスト | PHPUnit 13.3.4 + 実PostgreSQL。users・認証・依頼・担当/状態・コメントのHTTP検証を実装 |

AWS の月額予算 **3,000 円**、事前案内した期間のみ公開、独自ドメイン未所有は決定・確認済みです。今回の設計前提は **構築・検証6時間、公開48時間、閉鎖・保存・撤去等6時間の月60時間**。初月は検証に応じ公開を短縮します。

東京、CloudFront 従量課金・標準ドメイン HTTPS、VPC origin → 非公開 ALB、public IPv4 で外向き通信する Fargate、非公開 Single-AZ RDS、NAT Gateway・Redis なしは [基本設計案](docs/architecture.md)です。内部HTTPと標準証明書のTLS制約は、架空データ専用デモの例外として理由・残存リスク・見直し条件を記録しました。構築・実機検証済みという意味ではありません。

同期間の再デプロイはデータを保持し、次回公開は新規DBへ初期投入します。snapshotは取得後7日・通常最新1世代（復元検証中だけ期限内の一時併存）、アプリログ7日・アクセスログ30日。終了時にデモ資格情報・セッションを失効させ、削除と結果確認は作成者が担当します。秘密情報の誤投入は期限を待たず対応し、バックアップ費用枠は据え置きます。

## 決定済みの開発方針

- シフトレフトで開発し、要件・設計段階からセキュリティと検証方法を考えます。
- コンテナは必要最小限のベースイメージ、非 root 実行、マルチステージビルドを採用します。
- 秘密情報を Git やコンテナイメージに含めません。
- 秘密情報検出、依存関係検査、イメージ検査、SBOM 生成を開発工程に組み込みます。
- Terraform の権限、ネットワーク、状態ファイルの保護を検討します。
- テストや検査を無効化して成功扱いにしません。
- 日本語で説明し、小さな作業単位で変更理由と検証結果を残します。

## 未決定事項

| 項目 | 候補・決める内容 |
| --- | --- |
| サービス詳細 | users・認証・依頼・担当/状態・コメントAPIとローカル初期投入は採用済み。画面・AWS投入/運用の詳細はレビュー対象 |
| 技術の詳細 | フロントエンド・Terraform・RDSの対応版、画面案のレビュー |
| AWS の詳細 | AZ・サイズ・単一taskの性能、公開デモ資格情報の配布経路、予算通知先。内部通信の例外と保存・削除前提は基本設計に反映済み |
| 品質・検査 | イメージ検査・SBOM・IaC検査のツールと基準、証跡の保存先・期間。Gitleaks と Composer audit は採用、失敗を成功扱いにしない |

Cookie認証方針は採用し、ローカルで検証しました。具体的なAWS配置・HTTPS / trusted proxyの実機動作は未検証です。

## ドキュメント

- [AGENTS.md](AGENTS.md)：開発作業のルールと報告方法
- [要件整理](docs/requirements.md)：対象範囲、選定観点、受け入れ条件のたたき台
- [セキュリティ](docs/security.md)：セキュリティ要件、検証方法、未決定の運用基準
- [開発環境](docs/development.md)：Windowsでの起動・migration・テスト・停止、採用版と検証記録、秘密情報検査
- [AWS 基本設計](docs/architecture.md)：構成、認証、暗号化、公開・撤去・再構築の案
- [費用見積もり](docs/costs.md)：公式単価、月 60 時間の費用、予備費と超過要因
- [DB 設計](docs/database.md)：ER図、制約、ロック・競合、初期投入とデータ寿命
- [API 設計](docs/api.md)：認証、入出力、エラー、再取得・非再送
- [画面設計](docs/screens.md)：4画面の役割差分・遷移・状態と操作性
- [横断設計レビュー](docs/design-review.md)：FR・AC対応、A/B/X/Yの操作例、矛盾調整と検証計画
- [認証の実装](docs/authentication.md)：Laravel標準と追加処理、排他・失効・試行制限、実HTTP検証
- [依頼APIの実装](docs/requests.md)：採用した業務範囲、DB・認可・commit境界、FR/ACとテストの対応
- [担当・状態更新の実装](docs/request-workflow.md)：追加採用範囲、停止と更新のlock順、競合・rollback検証
- [コメントの実装](docs/comments.md)：投稿/閲覧、親ロックと完了の競合、原子性・FR/AC対応
- [ローカルdemo・HTTP](docs/demo.md)：FR-09の投入ガード、秘密値を表示しない資格情報生成、Windowsでの起動・接続・保持した停止

## 次に決めること（優先順）

1. **React画面**：Vite proxyで同一オリジンに接続し、4画面の操作性・XSS非実行・二重操作/結果不明の案内を実装・検証する。ローカル初期投入は採用済み。
2. **公開時の認証運用**：資格情報の配布経路、trusted proxy、session / cache清掃、要求の強制終了設定を決める。ハッシュコスト・単一タスク性能はAWSで別途検証する。
3. **リリース条件**：追加検査ツールと停止基準、CI必須チェック、予算通知先を決める。users の制約・正規化検証は実装済み。

## ローカルの users・認証・依頼・担当/状態検証

HTTPと初期データを利用する場合は、[FR-09の具体的手順](docs/demo.md)を使います。接続URLは`http://127.0.0.1:8000/api/me`で、未ログインの401は正常です。React画面はまだありません。下記は従来の自動テストだけの手順です。

Docker Desktop を Linux containers で起動し、Windows PowerShell でルートから1行ずつ実行します。各終了コードを確認し、失敗時は先へ進みません。ホストへの PHP / Composer 導入は不要です。

```powershell
python scripts/setup_local.py
docker compose build app
docker compose up -d --wait dev-db
docker compose run --rm app php artisan migrate --force
docker compose --profile test up -d --wait test-db
docker compose --profile test run --rm test
docker compose --profile test down
```

`.env` はローカル専用のランダム値を生成し、既存ファイルを上書きしません。通常のdownは開発DBのnamed volumeを保持し、テストDBだけ破棄します。down -vやmigrate:freshは使いません。変更後は再buildが必要です。認証テストはコンテナ内のloopbackに一時HTTPサーバーを起動してCookieを往復させます。Windowsへのport公開は不要です。個別suiteの実行・ローカルHTTPと公開Secure Cookieの区別は [開発手順](docs/development.md)を参照してください。
