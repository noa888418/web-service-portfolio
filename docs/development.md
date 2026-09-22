# 開発環境と Windows での秘密情報検査

## プロジェクトの決定事項と現在の範囲

React + TypeScript + Vite、Laravel、PostgreSQL、Docker、ECS、Terraform、GitHub Actionsの使用は決定済みです。AWS月額予算は3,000円、構築から撤去まで月60時間程度、公開は事前案内期間のみ。独自ドメインは未所有です。

現在導入・確認済みなのは下記の秘密情報検査環境です。Node / PHP / Composer / Laravel / Terraform 等の導入、Docker によるアプリ起動、AWS リソース作成は今回行っていません。バージョン・lock・追加検査ツールは実装段階で決めます。Python は検査補助用であり、バックエンドは Laravel です。

AWS のサブネット・SG・Cookie セッション・キャッシュ・OIDC・撤去は [基本設計案](architecture.md)、公式料金と構築・検証・撤去を含む 60 時間の試算は [費用見積もり](costs.md)を参照してください。調査済みの仕様と実機での検証済み事項を区別します。

今回のDB・API・4画面の案は [database.md](database.md)、[api.md](api.md)、[screens.md](screens.md)、FR/ACの対応・A/B/X/Yの机上確認・検証区分は [design-review.md](design-review.md)です。未承認の業務詳細を実装前にレビューし、DB制約とPostgreSQLの別接続による並行処理試験を計画します。アプリのテストは未実施です。

運用前提は架空データ専用、構築・検証6時間/公開48時間/閉鎖・保存・撤去等6時間（初月は検証に応じ公開短縮）。同期間は保持、次回公開は新DBへの初期投入。snapshot取得後7日・通常最新1世代、アプリログ7日・アクセスログ30日。作成者が公開終了時の資格情報・セッション失効と削除結果確認を担当します。誤投入は期限を待たず対応し、詳細はDB文書のOPS-01～OPS-07に従います。

## 採用した方法

Windows PowerShell と Git for Windows、Python 標準ライブラリで実行します。pre-commit パッケージ・Go・Docker は不要です。Git 標準の pre-commit フックを使うことで、追加導入を Gitleaks に絞ります。フックからは登録時の Python 実行ファイルを絶対パスで呼ぶため、VS Code の PATH の違いにも依存しにくい構成です。

Gitleaks のバージョンと Windows / Linux x64 配布物の SHA-256 は [config/gitleaks.json](../config/gitleaks.json) が唯一の定義元です。ローカル・CI の両方がこの定義を読みます。ダウンロード先は公式 GitHub Releases に固定し、照合が失敗した配布物は実行しません。最新バージョンへの自動追従は行いません。

現在の確認環境は Windows PowerShell 5.1、Git 2.48.1.windows.1、Python 3.13.2 です。`py` ランチャーは Python を認識しなかったため、手順では動作確認済みの `python` を使います。WSL や Git Bash へ切り替える必要はありません。Git フック自体は Git for Windows に付属する sh で起動します。

## 初回セットアップ・クローン後

VS Code のターミナルで Windows PowerShell を選び、リポジトリのルートに移動します。Python 3.10 以上が必要です。各コマンドの終了コードが 0 であることを確認して次へ進みます。

```powershell
$PSVersionTable.PSVersion
git --version
python --version
Get-Command git, python
git config --show-origin --get core.hooksPath
Get-ChildItem -Force .git/hooks
```

`core.hooksPath` が未設定の場合、`git config --get` は何も出力せず終了コード 1 を返します。これは検査失敗ではなく設定がないことを表します。有効な `pre-commit` ファイルや独自の hooksPath があれば、後述の共存方法を確認します。`.sample` ファイルは有効なフックではありません。

```powershell
python scripts/secrets.py install
python scripts/secrets.py install-hook
python scripts/secrets.py files
```

- install は公式の固定バージョンを `.tools/gitleaks/` 以下にダウンロードし、チェックサムと実行時のバージョンを確認します。グローバル PATH や Python のパッケージ環境は変更しません。
- install-hook はローカルの `.git/hooks/pre-commit` に登録します。**このファイルはクローンされないため、クローンした各環境で登録してください。** 同じ内容なら再登録できます。
- Python の配置を変えたときはフック内の呼び出し先も見直します。異なる内容の既存フックは自動上書きされません。
- PowerShell の実行ポリシー変更は不要です。今回 Codex からの外部ダウンロードと `.git/hooks` への書き込みにはサンドボックス外での実行許可が必要でした。
- Linux x64 向けにも配布物を固定していますが、今回のローカル動作確認は Windows のみです。WSL は別の Python・Git 環境なので、Windows と混在させず別クローンでセットアップしてください。

見本が必要なら `.env.example` を `.env` にコピーし、実際の値は `.env` 側だけに記入します。現在の `.env.example` はダミーで、アプリが動く設定ではありません。

## 手動検査と対象範囲

```powershell
# 管理対象の現在の内容（追跡済み + ignore されていない未追跡ファイル）
python scripts/secrets.py files

# 次のコミットに入るステージ差分。部分ステージにも対応
python scripts/secrets.py staged

# コミット作成後のローカル取得済み履歴全体
python scripts/secrets.py history

# 無視されるはずなのに既に追跡されているファイルの確認
git ls-files --cached --ignored --exclude-standard
```

`files` は Git が列挙したファイルを一時ディレクトリへ複製して検査します。未追跡の `.env` やダウンロードしたツールは対象外ですが、既に追跡されているファイルは ignore に追加しても対象に残します。`staged` は作業ファイルでなく index の差分を検査します。削除された値はコミットの新規内容に含まれませんが、過去のコミットは `history` で別途検査します。履歴がない状態での `history` は成功扱いにせず失敗します。

終了コード 0 は対象範囲に検出なし、非 0 は検出または実行エラーです。Gitleaks の未導入やバージョン違いでもフックを失敗させます。検出結果にはルール・ファイル・行などを表示しますが、秘密値は `--redact=100` で伏せます。伏字を外して共有ログに出したり、検査結果を無視してコミットしたりしないでください。

`.gitignore` は既に追跡されたファイルを解除しません。上記の確認で見つかった場合は、秘密値を出力せず対象を確認し、必要なら `git rm --cached -- <対象ファイル>` で追跡だけを解除します。履歴に実際の秘密情報が入っていた場合は、削除だけでなく失効・再発行と影響調査が必要です。初回の秘密情報検査環境の整備開始時には追跡済みファイルはありませんでした。

Terraform plan は `*.tfplan` / `*.plan` / `tfplan` / `plan.out` など、除外済みの命名を使います。任意名で出力した plan や独自の秘密情報ファイルは自動判別できないため、出力前に除外規則を追加します。`.terraform.lock.hcl`、`*.tfvars.example`、`*.tfvars.json.example` は管理できますが、見本に実値を入れないでください。

## 既存フックとの共存

登録スクリプトは異なる既存 pre-commit や `core.hooksPath` を見つけると、内容・設定を変更せず停止します。既存ツールの仕様に従い、現在のフックへ次の呼び出しを追加する方法を検討してください。

```sh
# Git は通常、非 bare リポジトリのルートで pre-commit を実行する。
python -I scripts/secrets.py staged || exit $?
# 既存の検査処理も継続し、その失敗を伝播する。
```

VS Code から Python が見つからない場合は、上記の `python` を確認済みの絶対パスに置き換え、空白を含むパスを引用します。既存処理の `exit` / `exec` より前に置くなど、両方が確実に実行される構成にします。pre-commit パッケージを既に使っている場合は、その設定に同じコマンドを呼ぶローカルフックを追加する方法もあります。ファイル名を渡さず、ステージ全体を検査する設定にしてください。

このリポジトリの開始時には既存フックがなかったため、実際の複数フック連携は未検証です。登録スクリプトが既存内容を壊さず停止することは隔離テストで確認しています。

## 誤検知を疑う場合

1. 伏字の結果にあるルール ID・ファイル・行を手元で確認し、実際の資格情報か、ダミーかを判断します。秘密値を issue やチャットへ貼りません。
2. 見本なら `replace-with-...` のような資格情報らしくない明確なプレースホルダーへの置き換えを優先し、再ステージして再検査します。
3. 除外が不可欠なら、誤検知の根拠、対象ルール・パス・値の条件、確認者、期限を記録し、レビュー可能な最小の除外を `.gitleaks.toml` に追加します。ファイル全体やルール全体を無効にせず、正常系と検出系を再検証します。

現在は既定ルールを継承し、独自 allowlist / baseline / `.gitleaksignore` はありません。インラインの `gitleaks:allow` は検査時に無視して検出を有効に保ちます。検査のスキップや失敗の握りつぶしは誤検知対応に使いません。

## 隔離環境での再検証

```powershell
python -I scripts/test_secret_controls.py
```

標準ライブラリのみで一時 Git リポジトリを作り、現在のファイルと導入済み Gitleaks を複製します。正常な内容でのコミット、ステージ側だけに残した未発行の検証用文字列による拒否、ログの伏字化、ツール不在時の拒否、既存フック保全、ignore 規則を確認します。ネットワーク通信や本物の認証情報は使用しません。

検証用文字列は実行時に一時ファイル内で組み立て、コミット拒否後に正常な内容へ置き換えます。本リポジトリにはステージもコミットもしません。一時リポジトリは終了時に削除します。検証の失敗時にも秘密値をそのまま表示しません。

## GitHub Actions の確認状況と残る確認

[secrets.yml](../.github/workflows/secrets.yml) は push と pull_request を対象に、履歴を `fetch-depth: 0` で取得し、Gitleaks を同じ定義から導入して履歴と現在のファイルを検査します。権限は `contents: read` のみです。個別の Secret や Gitleaks ライセンスキーは不要で、PR コメントやレポートのアップロードは行いません。checkout Action はコミット SHA に固定しています。

GitHub Actions の **初回 push に対する検査成功は利用者確認済み** です。利用者から緑色で成功したとの報告を得ています。実行 URL・対象 SHA・詳細ログは未提示で、こちらで直接確認した結果ではありません。各ステップのログや PR での動作まで確認済みとは扱いません。次の確認が残っています。

- PR で `Secret scan` / `Gitleaks` が起動・成功することを確認する。実行証跡を確認できる段階で、実行 URL・対象 SHA、ログのバージョン、Linux 配布物のチェックサム照合、検査対象を記録する。fork PR の初回実行など、承認待ちがある場合は実行状態を区別する。
- 検出時のジョブ失敗・伏字化は、必要なら本リポジトリの履歴を汚さない専用検証リポジトリで確認する。
- リポジトリの Rulesets / branch protection で `Gitleaks` を必須チェックにし、失敗や未実行でマージできないことを確認する。workflow ファイルだけではマージ禁止にならない。

CI は取得済みの参照から到達できる履歴を対象とし、未取得の履歴や Git 外の保存先は対象にしません。検査ルール・スクリプト・workflow の変更もレビュー対象です。

バージョン更新時は、公式配布物のバージョンと各 SHA-256 を同時に更新し、ローカルで install と隔離テストを再実行してから CI を確認します。

## 参照資料

- [Gitleaks v8.30.1](https://github.com/gitleaks/gitleaks/releases/tag/v8.30.1) と [公式チェックサム](https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/gitleaks_8.30.1_checksums.txt)
- [Gitleaks の利用方法・設定](https://github.com/gitleaks/gitleaks/tree/v8.30.1)：既定ルール継承、redact、Git 差分検査の根拠
- [actions/checkout](https://github.com/actions/checkout/tree/v4.2.2)：全履歴取得と認証情報の非保持
- [GitHub Actions のイベント](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows)：push / pull_request の実行条件

## 今後のアプリ・AWS 作業の進め方（提案・未実施）

1. 選定済み技術の対応バージョンを固定し、Docker 内で Vite の build と Laravel の依存関係取得・テストを再現する。実行 image は必要最小限の内容、非 root、マルチステージとする。秘密値を build args / VITE_* / image へ埋め込まない。
2. 依存関係検査、イメージ検査、SBOM、Terraform fmt / validate / IaC 検査を追加し、失敗をマージ・配布の成功扱いにしない。Terraform AWS Provider は調査時 v6.65.0 を参照したが、導入と validate は未実施。
3. 公開デモは `APP_ENV=production`、`APP_DEBUG=false` を使用する。データ投入の可否は `DEPLOYMENT_PURPOSE=demo` 等の用途、対象 account / DB の照合、ジョブごとの明示許可で分ける。既定は投入拒否とし、通常の起動・deployment から seed を呼ばない。
4. 通常デプロイは既存 DB を維持する差分 migration とし、単発 ECS task で結果を確認する。`migrate:fresh` は使わない。デモデータの reset は保存対象の確認と復元手順を伴う別作業にする。
5. 公開前に state・snapshot の保護と残り予算を確認し、公開終了時に CloudFront の閉鎖・関連付け解除・runtime 撤去・課金対象の残存を確認する。RDS 停止や ECS の task 数 0 だけで完了にしない。

Cookie 認証の API 検証では適切な CSRF 値を用意し、認可拒否と CSRF 拒否を区別します。CloudFront の URL が変わる再構築では APP_URL・許可ホスト・案内 URL を更新し、以前のセッションを引き継がないことを確認します。同じURLでも次回公開は旧セッションと資格情報を再利用しません。GitHub Actions からの AWS 認証は OIDC を用いる方針案で、現時点の secret scan workflow には AWS 権限を追加していません。

最初の実装単位は、採用バージョンとローカルPostgreSQL環境を確認したうえでのusers migration・メール正規化/一意性・役割/停止の制約検証を提案します。その後、Cookieログイン・me・logoutを一つの利用シナリオとして進めます。今回これらの実装・環境構築は行っていません。
