# 開発環境・users検証・Windowsでの秘密情報検査

## プロジェクトの決定事項と現在の範囲

React + TypeScript + Vite、Laravel、PostgreSQL、Docker、ECS、Terraform、GitHub Actionsの使用は決定済みです。AWS月額予算は3,000円、構築から撤去まで月60時間程度、公開は事前案内期間のみ。独自ドメインは未所有です。

秘密情報検査に加え、Laravel CLI・users・実PostgreSQLの検証基盤を実装しました。PHP / Composer はDocker内で使用し、ホストには導入していません。React・HTTP API・認証・Terraform・AWSは今回の対象外です。Python は検査・ローカル設定生成の補助用であり、バックエンドは Laravel です。

AWS のサブネット・SG・Cookie セッション・キャッシュ・OIDC・撤去は [基本設計案](architecture.md)、公式料金と構築・検証・撤去を含む 60 時間の試算は [費用見積もり](costs.md)を参照してください。調査済みの仕様と実機での検証済み事項を区別します。

DB・API・4画面の案は [database.md](database.md)、[api.md](api.md)、[screens.md](screens.md)、FR/ACの対応と検証区分は [design-review.md](design-review.md)です。usersの保存設計だけを今回採用し、下記のテストを実施しました。依頼・コメント等の未承認の業務詳細、認証、別接続による業務並行処理は次工程です。

運用前提は架空データ専用、構築・検証6時間/公開48時間/閉鎖・保存・撤去等6時間（初月は検証に応じ公開短縮）。同期間は保持、次回公開は新DBへの初期投入。snapshot取得後7日・通常最新1世代、アプリログ7日・アクセスログ30日。作成者が公開終了時の資格情報・セッション失効と削除結果確認を担当します。誤投入は期限を待たず対応し、詳細はDB文書のOPS-01～OPS-07に従います。

## 採用した方法

Windows PowerShell と Git for Windows、Python 標準ライブラリで実行します。秘密情報検査には pre-commit パッケージ・Go・Docker は不要です（users検証にはDockerを使用）。Git 標準の pre-commit フックを使うことで、秘密情報検査の追加導入を Gitleaks に絞ります。フックからは登録時の Python 実行ファイルを絶対パスで呼ぶため、VS Code の PATH の違いにも依存しにくい構成です。

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

`.env.example` は設定名と安全なプレースホルダーです。ローカルDBの起動には下記の `python scripts/setup_local.py` で専用の値を生成します。既存の `.env` は上書きしないため、不足項目は値をログに出さずローカルで確認します。

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

1. ローカルのPHP・Laravel・PostgreSQL・PHPUnitは下記の採用版で検証済み。次工程でVite・HTTPサーバー・APIを追加する。公開用imageは開発依存を除外し、秘密値を build args / VITE_* / image へ埋め込まない。
2. Composer audit以外の依存関係検査、イメージ検査、SBOM、Terraform fmt / validate / IaC 検査を追加し、失敗をマージ・配布の成功扱いにしない。Terraform AWS Provider は調査時 v6.65.0 を参照したが、導入と validate は未実施。
3. 公開デモは `APP_ENV=production`、`APP_DEBUG=false` を使用する。データ投入の可否は `DEPLOYMENT_PURPOSE=demo` 等の用途、対象 account / DB の照合、ジョブごとの明示許可で分ける。既定は投入拒否とし、通常の起動・deployment から seed を呼ばない。
4. 通常デプロイは既存 DB を維持する差分 migration とし、単発 ECS task で結果を確認する。`migrate:fresh` は使わない。デモデータの reset は保存対象の確認と復元手順を伴う別作業にする。
5. 公開前に state・snapshot の保護と残り予算を確認し、公開終了時に CloudFront の閉鎖・関連付け解除・runtime 撤去・課金対象の残存を確認する。RDS 停止や ECS の task 数 0 だけで完了にしない。

Cookie 認証の API 検証では適切な CSRF 値を用意し、認可拒否と CSRF 拒否を区別します。CloudFront の URL が変わる再構築では APP_URL・許可ホスト・案内 URL を更新し、以前のセッションを引き継がないことを確認します。同じURLでも次回公開は旧セッションと資格情報を再利用しません。GitHub Actions からの AWS 認証は OIDC を用いる方針案で、現時点の secret scan workflow には AWS 権限を追加していません。

最初の実装単位であるusers migration・メール正規化/一意性・役割/停止の保存検証は実施済みです。次はCookieログイン・me・logoutを一つの利用シナリオとして進めます。停止者拒否・auth_version照合・セッション失効はその工程で検証します。

## ローカル採用版と公式互換性確認（2026-09-22）

| 対象 | 実行確認した採用版 | 選定理由・公式根拠 |
| --- | --- | --- |
| Laravel | 13.32.0 | 13系はPHP 8.3～8.5対応、bug fixは2027年第3四半期、security fixは2028-03-17まで。12系はbug fix終了済みのため新規には13系を採用。[サポート表](https://laravel.com/docs/13.x/releases) |
| PHP | 8.4.25、CLI Alpine | Laravel13とPHPUnit13の対応範囲が重なる版。8.4はactive support 2026-12-31、security support 2028-12-31まで。8.5も候補だが今回必要な機能は8.4で満たせる。2026年末までに8.5移行を再評価。[サポート表](https://www.php.net/supported-versions.php) |
| Composer | 2.10.3 | Composer2の現行安定系を固定。必要PHPは7.2.5以上で8.4と互換。ComposerイメージからバイナリだけをPHP8.4へコピーし、別PHP版で依存を解決しない。[必要条件](https://getcomposer.org/doc/00-intro.md)、[配布元](https://getcomposer.org/download/) |
| PostgreSQL | 18.6、Alpine | 18系の修正版、保守期限2030-11-14。Laravelの対応DBに含まれる。17系も候補だが新規ローカル環境は長い保守期間を選択。RDSのengine版・拡張・費用はAWS工程で改めて確認。[保守表](https://www.postgresql.org/support/versioning/)、[Laravel対応DB](https://laravel.com/docs/13.x/database) |
| PHPUnit | 13.3.4 | PHP 8.4以上、13系bug fix期限2028-02-04。追加のPest層を置かずPHPUnitの標準テストを直接使用。[対応表](https://phpunit.de/supported-versions.html) |

正確なPHP依存は [composer.lock](../backend/composer.lock)、PHP・Composer imageは [Dockerfile](../docker/php/Dockerfile)、DB imageは [compose.yaml](../compose.yaml) のSHA-256 digestで固定します。Composer制約はPHP `~8.4.0` / Laravel `^13.0` / PHPUnit `^13.0`、通常のbuildは `install` でlockを再現します。digestの固定は更新不要という意味ではなく、修正版公開・脆弱性情報の確認時に意図的に更新し、全テスト・auditを再実行します。apk依存はbuild時に取得するため完全なbit単位再現性は保証しません。

PHPの必要拡張は [Laravel Deployment](https://laravel.com/docs/13.x/deployment) のCtype / cURL / DOM / Fileinfo / Filter / Hash / Mbstring / OpenSSL / PCRE / PDO / Session / Tokenizer / XMLと、PostgreSQL用pdo_pgsql、PHPUnit用XMLWriter等です。公式PHP imageの既存拡張を利用し、pdo_pgsqlだけ別stageでビルドします。`composer check-platform-reqs` をbuildで実施し、実拡張の存在とArgon2id動作も確認します。Redis / GD / Xdebug等は追加していません。[公式PHP image](https://github.com/docker-library/docs/blob/master/php/README.md)

### Windowsとコンテナの確認状況

Windows PowerShell 5.1、Git 2.48.1.windows.1、Python 3.13.2、Docker Desktop 4.38.0 (181591)、Engine 27.5.1、Compose v2.32.4-desktop.1を実行確認しました。contextは `desktop-linux`、serverはLinux/amd64です。Git Bash / WSLへの切り替えやホストへのPHP / Composer導入は不要でした。Docker自体の更新・再インストールはしていません。

別PCではDocker DesktopとLinux containersが必要です。`docker version` でClientだけでなくServer、`docker compose version` でComposeを確認します。Serverへ接続できないときはDesktopの起動・Linux containers・contextを確認し、未起動を成功扱いにしません。[Windows導入手順](https://docs.docker.com/desktop/setup/install/windows-install/)を参照し、必要なWSL2等は環境に合わせて導入します。

アプリは最小のPHP CLI Alpine、ビルド / 依存取得 / 実行のstageを分離し、実行UID/GIDは10001です。コンパイラー・Composer・root権限は最終imageに持ち込まず、PHPUnitはローカル検証用なので含めます。公開用FPM / Nginx imageではありません。`.dockerignore` は必要なbackendとDockerfileだけを許し、`.env`、vendor、Git情報をbuild contextへ渡しません。Composer scriptsの無実行は不要な生成処理を避けるためで、PHPUnit・auditは独立して必ず実行します。

PostgreSQL 18の公式imageは `PGDATA=/var/lib/postgresql/18/docker`、mount先は `/var/lib/postgresql` です。公式entrypointに初期ディレクトリの権限設定とpostgresユーザーへの切替を任せ、ComposeにDB用 `user:` を追加せず、`chmod 777` もしません。init scriptは空のデータ領域でのみ実行されます。[公式PostgreSQL imageの18以降の注意](https://github.com/docker-library/docs/blob/master/postgres/README.md)

`dev-db` はnamed volumeで永続化、`test-db` はtmpfsで終了時破棄、ネットワーク・DB名・パスワード・アプリDB roleも別です。5432等のホスト向けport公開はありません。アプリroleはNOSUPERUSER / NOCREATEDB / NOCREATEROLEで、ローカルmigration用に自分のDBを所有します。公開用Web roleのDDL禁止・権限分離は未実装です。DB接続の `sslmode=disable` はこの隔離ローカル環境だけの設定で、RDSへ持ち込みません。

### 起動・migration・テスト・停止

ルートのPowerShellから1行ずつ実行し、非0終了で止めます。アプリはソースをimageへコピーするため、変更後は `build app` を再実行します。

```powershell
docker version
docker compose version
python scripts/setup_local.py
docker compose config --quiet
docker compose build app
docker compose up -d --wait dev-db
docker compose run --rm app php artisan --version
docker compose run --rm app php artisan migrate --force
docker compose --profile test up -d --wait test-db
docker compose --profile test run --rm test
docker compose --profile test down
```

`setup_local.py` は暗号学的乱数からローカル専用のAPP_KEYとDBパスワードを生成し、Git管理外のルート `.env` へ保存します。値は表示せず既存ファイルを上書きしません。`.env.example` の見本値はそのまま実行に使いません。`.env` を更新しても既存dev volumeのDBパスワードは自動更新されないため、既存値を保持し、必要な変更はDB側と合わせて行います。解決のためにvolumeを削除しないでください。展開済み `docker compose config` やコンテナ全体のinspectは値を含むため共有ログに出しません。

通常の `down` はdev volumeを残し、次回同じ名前で再利用します。`down -v`、volume prune、`migrate:fresh` は日常手順に含めません。test-dbはtmpfsなので停止でデータを失います。今回のCLIにHTTPサーバー・ブラウザーURLはありません。公開デモのproduction設定・seed・初期化・障害復元は、このローカル作業と別のOPS手順です。

テストは起動時にAPP_ENV、接続方式、host=`test-db`、port、DB名 / role=`portfolio_test`を完全一致で確認し、接続URL等の上書き・Laravel config cacheを拒否します。接続後もDB名・role・非superuser・専用DBコメントを確認してから、ランダムな `users_test_...` schemaを作り、空schemaへ通常migrationします。終了時はそのschemaだけを削除し、publicや開発DBを初期化しません。各DBテストはtransactionをrollbackします。強制killでschemaが残る場合はテストDBを停止・再作成すれば解消し、開発volumeは操作しません。

安全ガードの手動確認例（これは**非0終了が期待結果**）：

```powershell
docker compose --profile test run --rm --no-deps -e DB_HOST=dev-db test
```

### 依存関係とCI

```powershell
docker compose --profile tools build composer
docker compose --profile tools run --rm composer validate --strict
docker compose --profile tools run --rm composer audit --locked --no-interaction
```

依存更新を意図した作業だけ `docker compose --profile tools run --rm composer update` を実行し、composer.json / lock差分をレビューして再build・テストします。toolingだけがbackendをbind mountし、app / testはmountしません。Linuxでtoolingが書けない場合はホストのUID / 所有権を確認し、全員書込権限で解決しません。

[users.yml](../.github/workflows/users.yml) はpush / pull_requestで同じDockerfile・lock・PostgreSQL imageを使い、build時のplatform確認、Composer audit、専用DB起動、PHPUnitの2回実行を行います。権限はcontents:readのみ。生成資格情報やDB内容をartifactに保存せず、AWS権限もありません。失敗を握りつぶさず後処理だけ `always()` で行います。[secrets.yml](../.github/workflows/secrets.yml) は変更せず維持します。

**users workflowはローカルと同じコマンドを設定した段階で、GitHubでの実行は未確認**です。push後にpush / PRの `Users PostgreSQL` と既存 `Gitleaks` のログ・SHA・成功 / 失敗伝播を確認し、Rulesets / branch protectionで両checkを必須にする作業を別途行います。workflow追加だけではマージ禁止になりません。

### 今回の検証記録（2026-09-22、未コミット作業ツリー）

- 通常migration：空の開発DBにusers / migrationsを作成して成功。テスト用の空schemaへの適用も毎回成功。
- PHPUnit：62テスト / 101アサーション、同じ実PostgreSQLで2回連続成功、warning / deprecationなし。2役割、正規化と形式検証の分離、重複、直接INSERTのUNIQUE / CHECK / NOT NULL、既定値、停止状態、複合参照、Argon2id、JSON非公開を確認。
- 保全：開発DBとテストDBのpublicに架空の検証専用行を置き、上記2回後もそれぞれ保持。開発users=0 / migrations=1、作業用schema残存=0を確認。検証専用表は確認後に撤去済み。
- 接続先：16パターンの不適切設定を単体テストで拒否。実際にDB_HOST=dev-dbへ変えた実行も接続前に非0終了。
- build：非root UID/GID10001、DBプロセスはpostgresユーザー、Composer strict validate / platform requirements成功。実行imageに.env・Composer・gccなしを確認。Composer auditは既知の脆弱性情報検出なし（将来の安全性を保証するものではない）。
- 秘密情報検査：Gitleaksのfiles / stagedは検出なし。隔離テストでダミーのコミット拒否・HEAD不変・伏字化・ツール不在時拒否・既存フック保全を確認。管理対象にignore対象の追跡ファイルなし。文書のローカルリンク99件とgit diff --check（作業ツリー / index）も確認。
- 修正した失敗：非rootの/app所有者不足、共有DBコメントの取得関数、一時表から通常表へのFKというテストDDL、DB既定値を未取得の比較、PHPUnit旧設定の非推奨警告。所有権・SQL・比較時点・設定を修正し、制約・テストを外して通していない。
- 未実施：ログイン・停止者拒否・セッション失効・CSRF・API / 画面、依頼とコメントの並行処理、seed、AWS、イメージ脆弱性検査 / SBOM、GitHub実行と必須チェック。アカウント停止フラグの保存テストを認証テストの成功と扱わない。
