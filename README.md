# 転職用 Web サービスポートフォリオ

小規模な Web サービスを開発し、要件整理・設計・実装・テスト・運用の考え方を示すプロジェクトです。成果物に加えて、技術の選定理由、設計上のトレードオフ、検証方法と結果を自分の言葉で面接で説明できることを目的とします。

題材は **「社内IT依頼・改善管理サービス」** に決定しました。単一会社の社員と IT担当者が、依頼登録・担当割り当て・状態変更・コメントで受付から完了までを管理する初期版を提案しています。詳細な機能・権限・上限・対象外は [要件案](docs/requirements.md) のレビュー対象で、確定仕様ではありません。

## 現在の状態と今回の範囲

初期ドキュメントに加え、秘密情報の混入を防ぐ `.gitignore`、Gitleaks のコミット前検査、GitHub Actions の秘密情報検査を整備しました。この作業環境ではローカルフックの登録と隔離リポジトリでの検証が完了しています。GitHub Actions の初回 push に対する検査成功は **利用者確認済み** です。実行 URL・対象 SHA・詳細ログは未提示で、こちらでの直接確認はしていません。PR の検査、CI での検出時の失敗、必須チェック設定は別途確認が必要です。

ローカル検証基盤と **users の保存・制約**を実装しました。Laravel の CLI 最小構成、非rootの Docker イメージ、開発DBとテストDBを分離する Compose、実PostgreSQLの PHPUnit テストがあります。users の設計だけを今回の実装前提として採用し、認証・依頼・コメントなどの業務詳細は提案のままです。画面・HTTP API・Terraform・AWS は未実装です。

DB・API12件・共通4画面と FR-01～FR-09 / AC-01～AC-19 の [横断対応表](docs/design-review.md)があります。users はローカルで62テスト・101アサーションが成功しました。停止フラグの保存確認を、停止者のログイン拒否・セッション失効の検証と同一視しません。users 用の Actions workflow は追加済みですが **GitHub上では未実行**、必須チェック設定も未確認です。

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
| 自動テスト | users は PHPUnit 13.3.4 + 実PostgreSQL。画面・API の追加検証は次工程 |

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
| サービス詳細 | 題材・デモ運用前提は確定。単一会社・2役割、初期機能、操作権限、状態遷移、入力上限と追加設計はレビュー対象 |
| 技術の詳細 | フロントエンド・Terraform・RDS の対応版、認証期限・試行制限値、users 以外の DB・API・画面案のレビュー |
| AWS の詳細 | AZ・サイズ・単一taskの性能、公開デモ資格情報の配布経路、予算通知先。内部通信の例外と保存・削除前提は基本設計に反映済み |
| 品質・検査 | イメージ検査・SBOM・IaC検査のツールと基準、証跡の保存先・期間。Gitleaks と Composer audit は採用、失敗を成功扱いにしない |

確定した技術構成と予算・公開条件に対して、Cookie セッション認証と具体的な AWS 配置は提案段階です。公式仕様で成立条件を確認したことと、実機で動作確認したことを区別します。

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

## 次に決めること（優先順）

1. **初期版の要件案**：権限の分担、完了・訂正の扱い、入力上限とデータ運用をレビューし、採用する詳細仕様を決める。
2. **認証と実装の詳細**：期限・試行制限値・資格情報の配布経路を決め、Cookieログイン・me・logoutの実装へ進む。ハッシュコスト・単一タスク性能はAWSで別途検証する。
3. **リリース条件**：追加検査ツールと停止基準、CI必須チェック、予算通知先を決める。users の制約・正規化検証は実装済み。

## ローカルの users 検証

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

`.env` はローカル専用のランダム値を生成し、既存ファイルを上書きしません。通常の `down` は開発DBの named volume を保持し、テストDBの内容は破棄します。`down -v` や `migrate:fresh` は使いません。ソース変更後は再buildが必要です。現在はCLI検証のみでブラウザー用URLはありません。詳細・公式の対応版根拠は [開発手順](docs/development.md)に記載しています。
