# 開発環境・users / 認証検証・Windowsでの秘密情報検査

## プロジェクトの決定事項と現在の範囲

最新工程は**Reactログイン・一覧・ログアウト**です。[frontend.md](frontend.md)に採用版と、失敗時停止付きPowerShellの起動/検証/停止手順を記録しました。ブラウザーは127.0.0.1:5173へ統一します。以下の従来工程の記録は当時の範囲を残し、今回の結果は末尾に追記します。

React + TypeScript + Vite、Laravel、PostgreSQL、Docker、ECS、Terraform、GitHub Actionsの使用は決定済みです。AWS月額予算は3,000円、構築から撤去まで月60時間程度、公開は事前案内期間のみ。独自ドメインは未所有です。

秘密情報検査、Laravel・users・認証・依頼・担当/状態・コメントに続き、FR-09のローカル初期投入と通常HTTP起動を実装しました。PHP / ComposerはDocker内で使用します。React・AWSへの投入・Terraformは今回の対象外です。Pythonは検査・ローカル設定生成の補助用です。過去の検証記録は当時の結果を維持し、最新結果は末尾のFR-09工程記録に分けます。

AWS のサブネット・SG・Cookie セッション・キャッシュ・OIDC・撤去は [基本設計案](architecture.md)、公式料金と構築・検証・撤去を含む 60 時間の試算は [費用見積もり](costs.md)を参照してください。調査済みの仕様と実機での検証済み事項を区別します。

DB・API・4画面は [database.md](database.md)、[api.md](api.md)、[screens.md](screens.md)、対応は [design-review.md](design-review.md)です。認証の実装差分は [authentication.md](authentication.md)、コメント採用範囲・並行処理は [comments.md](comments.md)、過去の初回準備は [demo.md](demo.md)、ログイン/一覧の起動・操作・停止は [frontend.md](frontend.md)に記録します。登録/詳細画面・公開運用は次工程です。

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

1. ローカルのPHP・Laravel・PostgreSQL・PHPUnitと認証HTTP APIは検証済み。次工程で業務API・Vite・公開用FPM / Nginxを追加する。公開用imageは開発依存を除外し、秘密値を build args / VITE_* / image へ埋め込まない。
2. Composer audit以外の依存関係検査、イメージ検査、SBOM、Terraform fmt / validate / IaC 検査を追加し、失敗をマージ・配布の成功扱いにしない。Terraform AWS Provider は調査時 v6.65.0 を参照したが、導入と validate は未実施。
3. 公開デモは `APP_ENV=production`、`APP_DEBUG=false` を使用する。データ投入の可否は `DEPLOYMENT_PURPOSE=demo` 等の用途、対象 account / DB の照合、ジョブごとの明示許可で分ける。既定は投入拒否とし、通常の起動・deployment から seed を呼ばない。
4. 通常デプロイは既存 DB を維持する差分 migration とし、単発 ECS task で結果を確認する。`migrate:fresh` は使わない。デモデータの reset は保存対象の確認と復元手順を伴う別作業にする。
5. 公開前に state・snapshot の保護と残り予算を確認し、公開終了時に CloudFront の閉鎖・関連付け解除・runtime 撤去・課金対象の残存を確認する。RDS 停止や ECS の task 数 0 だけで完了にしない。

Cookie 認証の API 検証では適切な CSRF 値を用意し、認可拒否と CSRF 拒否を区別します。CloudFront の URL が変わる再構築では APP_URL・許可ホスト・案内 URL を更新し、以前のセッションを引き継がないことを確認します。同じURLでも次回公開は旧セッションと資格情報を再利用しません。GitHub Actions からの AWS 認証は OIDC を用いる方針案で、現時点の secret scan workflow には AWS 権限を追加していません。

users保存検証に続き、Cookieログイン・me・logout、停止者拒否・auth_version照合・セッション失効を実装しました。公開経路と他の業務機能は次工程です。

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

通常のdownはdev volumeを残し、次回同じ名前で再利用します。down -v、volume prune、migrate:freshは日常手順に含めません。test-dbはtmpfsなので停止でデータを失います。認証テストでは一時HTTPサーバーを自動起動・撤去します。公開デモのseed・初期化・障害復元は別のOPS手順です。

テストは起動時にAPP_ENV、接続方式、host=`test-db`、port、DB名 / role=`portfolio_test`を完全一致で確認し、接続URL等の上書き・Laravel config cacheを拒否します。接続後もDB名・role・非superuser・専用DBコメントを確認してから、ランダムなusers_test_... schemaを作り、通常migrationします。終了時はそのschemaだけ削除します。usersテストはtransactionをrollback、HTTPテストは別プロセスから見えるようfixtureをcommitし、そのschema内の行だけを各ケース後に削除します。publicや開発DBには触れません。強制killでschemaが残る場合はtest-dbを再作成し、開発volumeを操作しません。

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

[users.yml](../.github/workflows/users.yml) はpush / pull_requestで同じDockerfile・lock・PostgreSQL imageを使い、platform確認、Composer audit、Users・Authentication・Requests・Workflow・Commentsの各suiteと全suite再実行を行います。既存のcheck名Users PostgreSQLを維持します。権限はcontents:readのみ。生成資格情報やDB内容をartifactに保存せず、AWS権限もありません。失敗を握りつぶさず後処理だけalways()で行います。[secrets.yml](../.github/workflows/secrets.yml) は変更せず維持します。

**users workflowはローカルと同じコマンドを設定した段階で、GitHubでの実行は未確認**です。push後にpush / PRの `Users PostgreSQL` と既存 `Gitleaks` のログ・SHA・成功 / 失敗伝播を確認し、Rulesets / branch protectionで両checkを必須にする作業を別途行います。workflow追加だけではマージ禁止になりません。

### users工程当時の検証記録（2026-09-22）

- 通常migration：空の開発DBにusers / migrationsを作成して成功。テスト用の空schemaへの適用も毎回成功。
- PHPUnit：62テスト / 101アサーション、同じ実PostgreSQLで2回連続成功、warning / deprecationなし。2役割、正規化と形式検証の分離、重複、直接INSERTのUNIQUE / CHECK / NOT NULL、既定値、停止状態、複合参照、Argon2id、JSON非公開を確認。
- 保全：開発DBとテストDBのpublicに架空の検証専用行を置き、上記2回後もそれぞれ保持。開発users=0 / migrations=1、作業用schema残存=0を確認。検証専用表は確認後に撤去済み。
- 接続先：16パターンの不適切設定を単体テストで拒否。実際にDB_HOST=dev-dbへ変えた実行も接続前に非0終了。
- build：非root UID/GID10001、DBプロセスはpostgresユーザー、Composer strict validate / platform requirements成功。実行imageに.env・Composer・gccなしを確認。Composer auditは既知の脆弱性情報検出なし（将来の安全性を保証するものではない）。
- 秘密情報検査：Gitleaksのfiles / stagedは検出なし。隔離テストでダミーのコミット拒否・HEAD不変・伏字化・ツール不在時拒否・既存フック保全を確認。管理対象にignore対象の追跡ファイルなし。文書のローカルリンク99件とgit diff --check（作業ツリー / index）も確認。
- 修正した失敗：非rootの/app所有者不足、共有DBコメントの取得関数、一時表から通常表へのFKというテストDDL、DB既定値を未取得の比較、PHPUnit旧設定の非推奨警告。所有権・SQL・比較時点・設定を修正し、制約・テストを外して通していない。
- 未実施：ログイン・停止者拒否・セッション失効・CSRF・API / 画面、依頼とコメントの並行処理、seed、AWS、イメージ脆弱性検査 / SBOM、GitHub実行と必須チェック。アカウント停止フラグの保存テストを認証テストの成功と扱わない。

### 認証追加後のWindows再実行（2026-09-27）

この節が現在の認証検証手順です。上の09-22の記録は当時の結果として残します。利用者からもusersの62テスト・101アサーション・終了コード0がWindowsで成功したと確認を得ています。

Sanctum **4.3.3**を追加し、既存のPHP 8.4.25 / Laravel 13.32.0 / Composer 2.10.3 / PostgreSQL 18.6 / PHPUnit 13.3.4は維持しました。既存lock packageの更新・削除はなく、Sanctumのみ追加です。設計理由と公式資料（09-27確認）は [authentication.md](authentication.md)を参照してください。

Docker Desktopを起動し、ルートのPowerShellで1行ずつ実行します。各コマンド直後の `$LASTEXITCODE` が0であることを確認し、非0なら後続へ進みません。

```powershell
# 既存.envは上書きしない。クローン直後にも使用できる
python scripts/setup_local.py
docker compose config --quiet
docker compose build app
docker compose --profile test up -d --wait test-db

# 通常はこちら1回でusers + 認証の全検証
docker compose --profile test run --rm test
$LASTEXITCODE

# 切り分けたい場合のみ個別suite（CIもこの名前で実行）
docker compose --profile test run --rm test php vendor/bin/phpunit --testsuite Users
docker compose --profile test run --rm test php vendor/bin/phpunit --testsuite Authentication

# dev volumeを維持して停止。-vは付けない
docker compose --profile test down
```

テストでは実HTTPサーバー2プロセスとCookie保持クライアントをコンテナ内で自動起動し、CSRF取得→login→me→logout→meの401まで検証します。HTTP / DBポートをホストへ公開する必要はありません。WindowsへPHPやcurlを追加導入する必要もありません。テスト資格情報は一時schemaのfixture専用で、公開デモへ配布・投入するものではありません。テスト用clockや並行処理ルートもpublic/index.phpでは読まれません。

通常の開発DBへ管理表を追加する場合は、既存の「起動・migration」の `docker compose up -d --wait dev-db` → `docker compose run --rm app php artisan migrate --force` を使用します。これは差分migrationで、usersを初期化しません。今回の認証検証で開発DBの起動・migration・初期化は行っていません。

ローカルHTTPはComposeの `SESSION_SECURE_COOKIE=false` だけを使います。公開では `APP_ENV=production` によりSecure=trueを強制し、HttpOnly / SameSite=Lax / host-onlyを維持します。公開用proxy / HTTPS疎通をローカルHTTPで検証済みにしません。

### 認証工程の検証記録（2026-09-27、未コミット作業ツリー）

この83テスト・645アサーション・終了コード0は、その後利用者のWindowsでの再実行成功も確認済みです。GitHub CIの対象SHA・実行URLの証跡とは区別します。

- 全suite：**83テスト・645アサーション、終了コード0**、同じDBで再実行も成功。個別のUsersは**62テスト・101アサーション**、Authenticationは**21テスト・544アサーション**で、ともに終了コード0。CSRFを無効化せず、actingAsでフローを省略していません。
- 保全：専用test-dbのpublicへ検証専用行を置き、個別suiteと全suiteの実行後も保持、一時schema残存0を確認。作成した検証用表だけ確認後に撤去。既存接続先ガードは変更せず、開発DBは起動・変更していません。
- 有効な2役割、共通認証失敗、session ID更新と旧Cookie拒否、logout、停止・auth_version、制御時計で30分/8時間の境界、試行枠5/30回・60秒回復・Retry-After、保護項目、最小JSON・Cookie属性・ログ秘匿を確認。
- 並行処理：独立したHTTPプロセス、同期点とPGの待機観測で保存対logout/停止、同メールcounter競合を確認。advisory lockの競合待機は4.8～7秒の期待範囲で503、その後の解放・logoutも成功。要求全体の20秒強制終了や通信断の上限は未保証。
- Composer audit：Sanctum追加後のlockに既知の脆弱性情報検出なし。既存依存の不要な更新はなし。
- 修正した検証失敗：テスト補助メソッド名statusがPHPUnitのfinalメソッドと衝突したためassertResponseへ改名。実装の制約やCSRF・失敗判定を緩めていません。
- 未検証：今回追加したActionsのGitHub実行・必須チェック、実HTTPS・CloudFront/proxy・Fargate負荷、DBネットワーク断/公開worker強制終了、期限切れsession/cacheの定期清掃。公開の資格情報配布・一括失効ジョブ、React・業務機能・AWSは未実装です。

### 依頼API追加後のWindows手順と記録（2026-09-27）

レビュー単位は①service_requestsの保存制約、②登録・閲覧・sessionと同じcommit境界、③HTTP/DBテスト・CI・文書です。[requests.md](requests.md)に採用範囲・FR/AC対応・設計変更の理由を記録しました。PHP/Laravel/Sanctum/PostgreSQL等の採用版・lock・Docker構成・DB接続先ガードは変更していません。

Docker Desktopを起動し、ルートのPowerShellで以下を1行ずつ実行します。各行直後の `$LASTEXITCODE` が非0なら停止します。

```powershell
python scripts/setup_local.py
docker compose build app
docker compose --profile test up -d --wait test-db

# users・認証・依頼をまとめて実行（通常はこちら）
docker compose --profile test run --rm test
$LASTEXITCODE

# 依頼だけを切り分ける場合（CIにも同じsuiteを追加）
docker compose --profile test run --rm test php vendor/bin/phpunit --testsuite Requests
$LASTEXITCODE

# 開発DBのvolumeは維持する。-vは付けない
docker compose --profile test down
```

開発DBへservice_requestsを追加するときだけ、既存の起動手順に従い `docker compose up -d --wait dev-db` → `docker compose run --rm app php artisan migrate --force` を実行します。既存users/sessionを削除する操作はありません。今回の検証ではdev-dbを起動・変更せず、接続先ガード済みtest-dbのランダムschemaを使いました。HTTP fixtureは公開用seedや配布資格情報ではありません。

- 全suite：**155テスト・1,656アサーション、終了コード0**。内訳はUsers 62 / 101、Authentication 21 / 544、Requests 72 / 1,011。既存テストはmigration件数の期待値2→3だけ変更し、検査を削除・無効化していません。
- 再実行・保全：同じDBで全suiteを再実行し同じ結果。専用test-dbのpublicに置いた検証行を保持、一時schema残存0、確認用表は撤去済みです。DB_HOST=dev-dbの誤設定は接続前に終了コード1で拒否。dev-dbとnamed volumeは変更していません。
- 文書・秘密情報：ローカルリンク148件の参照先、作業ツリー/indexのgit diff --check、新規ファイルの末尾空白を確認。Gitleaksのfiles / stagedは検出なし、ignore対象の追跡ファイルなし。Gitleaksの設定・workflow・既存フックは変更していません。
- 正常系・異常系：A/B/X/Yの実Cookieログイン、所有者・初期値、登録権限、本人スコープ・同一404、保護項目、Unicode上限・不正値、0/20/21件・固定順・範囲外、CSRF、停止/失効を確認。SQLite・actingAs・CSRF無効化は使用していません。
- commit境界：session書込をDB triggerで故意に失敗させ503・依頼0、INSERT後の非DB例外でも500・依頼0。別HTTP workerと同期点で、登録未commit時の別接続不可視、停止のusers lock待機、登録→session保存→commit→停止/失効を確認しました。
- 修正したテストの誤り：PostgreSQLのRESTRICT拒否はSQLSTATE 23001（通常の参照先不存在23503とは別）。停止がsessionを削除した後の旧POSTはCSRF先行で419、GETは401。これらの期待値を既存契約に合わせ、正しいCSRF更新後のPOSTも401であることを追加確認しました。
- GitHub CI：Requests suiteを既存workflowへ追加した段階です。対象コミットの実行URL・結果証跡がないため成功とは扱いません。check名Users PostgreSQLとGitleaksを維持し、必須チェック設定は別途確認します。
- 未実施：担当/状態変更、FR-05のコメント、ReactでのHTML非実行・操作性、AWS/CloudFrontのAC-19、公開DB権限分離・性能測定。APIがタグ風文字列をJSONとして返すことと、ブラウザーでXSSが起きないことを区別します。

### 担当・状態更新のWindows手順と記録（2026-09-28）

FR-06/07・AC-14の担当/状態更新競合を採用しました。[request-workflow.md](request-workflow.md)に認可・エラー・lock順の設計調整とFR/AC対応を記録しています。前工程の155テスト・1,656アサーション・終了コード0は利用者のWindowsでも確認済みです。今回の追加分を利用者が再実行したとは記録しません。

PowerShellでルートから1行ずつ実行し、各コマンド直後の `$LASTEXITCODE` が非0なら次へ進まず原因を確認します。Docker DesktopはLinux containersを使用します。

```powershell
# 既存.envを上書きせず、不足時だけローカル値を生成
python scripts/setup_local.py
docker compose build app
docker compose --profile test up -d --wait test-db

# 通常はこちら。Users / Authentication / Requests / Workflowを全実行
docker compose --profile test run --rm test
$LASTEXITCODE

# 担当・状態だけを切り分ける場合
docker compose --profile test run --rm test php vendor/bin/phpunit --testsuite Workflow
$LASTEXITCODE

python scripts/secrets.py files
python scripts/secrets.py staged

# -vは付けない。開発DBのnamed volumeを保持
docker compose --profile test down
```

今回migration追加はありません。既存3本のmigrationが適用済みなら開発DBへの追加操作は不要です。テストはガード済みtest-dbのランダムschemaへ3本を適用し、終了時そのschemaだけを削除します。dev-dbへの接続・migration・初期化は今回実施していません。HTTPサーバー2プロセスとCookieクライアントはコンテナ内で自動起動し、ホストへのポート公開・PHP/curl導入は不要です。テスト用架空資格情報・障害注入routerは公開デモseedや配布資格情報と別です。

| 検証 | 結果 |
| --- | --- |
| 全体回帰・再実行 | **193テスト・2,707アサーション、終了コード0**。Users 62 / 101、Authentication 21 / 544、Requests 72 / 1,023、Workflow 38 / 1,039。既存155テストを維持 |
| 検査追加の経緯 | Workflow初回38 / 1,034成功、全体193 / 2,702成功。その後「停止した同一担当への変更も409」と成功時updated_atの確認を補強し、最終版の全体193 / 2,707成功 |
| 業務・認証 | 有効IT候補・20件ページング、担当変更/解除、16状態組合せ、停止担当の修復、社員403/他人404、CSRF/失効/保護項目、拒否時全業務列不変 |
| 同時更新 | 異なるIT/セッション/HTTPプロセス/DB接続。同じ版から担当同士・状態同士・相互で200/409。同期点とpg_stat_activityで待機を観測し、versionが一度だけ増加 |
| 停止競合とrollback | 割り当て先行では停止が待機、停止先行lock中は503・停止確定後は422。session保存失敗503とUPDATE後例外500で担当/状態/version/updated_at全てrollback |
| データ保全 | 再実行前にtest-db publicへ置いた確認行id=28が再実行後も保持。一時schema残存0、確認表は検証後撤去。DB_HOST=dev-dbは接続前に終了コード1で拒否（意図した失敗） |
| 既存版・構成 | PHP8.4.25 / Laravel13.32.0 / Sanctum4.3.3 / Composer2.10.3 / PostgreSQL18.6 / PHPUnit13.3.4を維持。lock・既存migration・DB接続先ガード・Docker構成は変更なし |
| 文書・秘密情報 | ローカルリンク175件、FR/AC・API/状態/権限・採用範囲、作業ツリー/indexのgit diff --check、新規ファイルの末尾空白を確認。Gitleaks files / stagedは検出なし（stagedは差分0）。ignore対象の追跡ファイルなし。秘密情報検査の設定・workflow・フックは変更なし |

CIにはWorkflow suiteを追加し、全suiteの再実行・contents:read・既存check名Users PostgreSQL・Gitleaksを維持しました。GitHub上の対象コミット・実行URL・結果は未確認で、CI成功とは扱いません。必須チェック設定はworkflow追加と別途確認が必要です。ローカルのcommit・pushは実施していません。

未実装/未検証：コメント・AC-14のコメント投稿対完了、React、公開デモseed、AWS、CloudFront/実HTTPS・公開DB権限分離・負荷/公開worker終了/通信断のtimeout。候補usersのNOWAITは停止競合の循環待機を避ける代わりに503を返す場合があり、画面では自動再送せず最新詳細を確認する設計です。

### コメント工程のWindows手順と記録（2026-09-28）

FR-05のコメント閲覧、FR-08の投稿、AC-14の投稿対完了を採用し、[comments.md](comments.md)へ設計理由・API/FR/AC対応を記録しました。前工程の193テスト・2,707アサーション・終了コード0は利用者のWindowsでも確認済みです。今回追加分の利用者再実行やGitHub CI成功は未確認です。

Docker DesktopをLinux containersで起動し、リポジトリのルートのPowerShellで1行ずつ実行します。各行直後の `$LASTEXITCODE` が非0なら停止して原因を確認してください。

```powershell
python scripts/setup_local.py
docker compose build app
docker compose --profile test up -d --wait test-db

# 既存users・認証・依頼・担当/状態・コメントの全体検証
docker compose --profile test run --rm test
$LASTEXITCODE

# コメントだけを切り分けたい場合
docker compose --profile test run --rm test php vendor/bin/phpunit --testsuite Comments
$LASTEXITCODE

python scripts/secrets.py files
python scripts/secrets.py staged
docker compose --profile test down
```

downに `-v` は付けません。テストは専用test-dbと実行ごとのランダムschemaを使い、既存の接続先/role/DB識別ガードを変更していません。テスト用Cookie・パスワードはコンテナ内の架空fixture専用で、公開資格情報やseedではありません。ホストへHTTP/DBポートは公開せず、本番Secure Cookie設定を弱めていません。

開発DBにcomments表を適用するときだけ、build後に既存手順の `docker compose up -d --wait dev-db` → `docker compose run --rm app php artisan migrate --force` を実行します。今回は既存3本を変更せず4本目の追加migrationなので、既存users・依頼を消しません。migrate:freshやseedは実行しません。今回の作業では開発DBに接続・migrationしていません。

| 検証 | この作業環境での結果 |
| --- | --- |
| コメント単独 | Comments **43テスト・769アサーション、終了コード0** |
| 全体回帰 | **236テスト・3,488アサーション、終了コード0**。Users 62 / 101、Authentication 21 / 544、Requests 72 / 1,035、Workflow 38 / 1,039、Comments 43 / 769 |
| 既存テストの変更 | usersのmigration期待件数を3→4へ変更。コメントGETの旧404確認を、編集/削除の405・子パス404へ置換。新APIの認可・入力・状態検査はComments suite。既存193テストを維持 |
| DB保存・追加migration | 直接SQLのFK/NOT NULL/長さCHECK、identity/UTC日時/index、親CASCADE/投稿者RESTRICT、モデルのmass assignment拒否を確認。専用schema内で追加migration前後の既存users/依頼保持も検証 |
| API境界 | 本人/IT可・別社員404/件数非露出、投稿者偽装・保護項目、正規化/1～2000境界/不正値、タグ風文字列、0/20/21件・日時/ID順・範囲外、完了後閲覧/投稿409、CSRF/停止/期限/auth_version、親全列不変 |
| 競合・rollback | 別利用者/別Cookie/別HTTP worker/DB接続・同期点。投稿先行201→完了200、完了先行200→投稿409、2投稿とも201。session保存失敗503・INSERT後例外500でコメント0・親不変 |
| 保全・ガード | 全体実行前にtest-db publicへ置いた確認行id=28が保持。一時schema残存0、確認表は検証後撤去。DB_HOST=dev-dbは接続前に終了コード1で拒否（意図した失敗）。開発DB/named volumeは変更なし |
| 文書・秘密情報 | ローカルリンク196件、FR/AC・権限/項目/状態・採用範囲、作業ツリー/indexのgit diff --check、新規ファイルの末尾空白を確認。Gitleaks files / stagedは検出なし（ステージ差分0）、ignore対象の追跡ファイルなし。Gitleaks設定・workflow・フックは変更なし |

CIにComments suiteを追加し、全体再実行・contents:read・既存check名Users PostgreSQL・Gitleaksを維持しました。対象コミット・実行URL・結果を確認できていないためGitHub成功とは記録しません。必須チェック設定はworkflow追加とは別です。commit・pushは実施していません。

未実装/未検証：ReactでのXSS非実行・操作性/二重送信/結果不明の案内、FR-09公開デモseed、Terraform・AWS、実HTTPS/CloudFront、公開DB権限分離・負荷・worker終了/通信断のtimeout。本文をJSON文字列として往復できる検証を、ブラウザーや公開経路全体の安全性保証に広げません。

## FR-09・ローカルHTTP工程の最新記録（2026-09-29）

利用者から前工程236テスト・3,488アサーション・終了コード0のWindows再実行成功を受領しました。今回開始時のHEADは`49fa8f5f3e3f1703e01f1ed98ecc1fadb31e97c4`、未コミット差分なしでした。今回の変更は未commitで、GitHubの対象SHA・実行URL・結果は確認していません。既存workflowにDemo suiteと資格情報生成テストを追加しましたが、workflow追加とCI成功・必須チェック設定は別です。

手順と設計理由は [demo.md](demo.md)に集約しました。基盤（Composeのseed/http・資格情報生成）、FR-09（追加migration・ガード・手動command）、テスト/CI、文書を別のレビュー単位として確認できます。採用版とlockは変更していません。Laravel13.32.0 / PHP8.4.25 / Composer2.10.3 / PostgreSQL18.6 / PHPUnit13.3.4 / Sanctum4.3.3、Gitleaks8.30.1を維持しています。

| 検証 | 結果・証跡の範囲 |
| --- | --- |
| Demo単独 | **34テスト・367アサーション・終了コード0**。投入条件・no-op・rollback・同期付き別process投入・通常public/index.phpの12 API |
| 全体回帰 | **270テスト・3,855アサーション・終了コード0**。既存236 / 3,488を維持しDemo 34 / 367を追加 |
| 資格情報生成 | `python scripts/test_demo_setup.py` 1テスト成功。ランダム4件・既存ファイル非上書き。実ファイルのGit除外を確認 |
| 接続先ガード | `DB_HOST=dev-db`をテスト起動へ指定するとbootstrapで拒否、期待どおり終了コード1。開発DBへの接続・初期化なし |
| 再実行の保全 | Demo再実行も34 / 367成功。test-dbのpublicに置いた今回専用の確認行29を保持、ランダムtest schema残存0。確認用tableだけ撤去。開発DBも4/4/4/1を保持 |
| 開発DB | 事前にmigrations/usersだけ、users 0件を確認。通常migrationで残り4本を追加、明示seedでcreated、再実行no-op。4利用者・4依頼・4コメント・成功記録1件 |
| Windows→通常HTTP | Cookie保持クライアントで4人のlogin/me/一覧/詳細/コメント/logout/401成功。停止・再起動後も成功、業務件数4/4/4/1を保持 |
| コンテナ | http UID10001、127.0.0.1:8000 publish、DB port非公開。http内に資格情報ファイルなし。既存の非root・multi-stageイメージを利用 |
| 秘密情報制御 | `python -I scripts/test_secret_controls.py`成功。隔離repoで検出時commit拒否・redaction・既存hook共存・検査器欠落時失敗、本repoのHEAD/index不変 |
| 最終静的検査 | 文書ローカルリンク、`git diff --check` / `--cached --check`成功。Gitleaks files / staged成功（stagedは0件）。追跡済みignore対象なし |

途中の失敗も区別します。DBの実IP照合は`inet_server_addr()::text`が`/32`付きになるため正しい接続も拒否していました。`host(inet_server_addr())`でアドレスを取得し、照合を維持して修正しました。Python生成器のstdlib名が既存scripts/secrets.pyと衝突したため、標準os.urandomとbase64へ変更しました。いずれも修正後のテストが上記結果です。

実ローカル投入では、制限ユーザーが作ったファイルのACLでDocker Desktopがmountを拒否しました。確認済みの通常ユーザーSIDへ権限を与え、値を変えず再実行して成功しました。HTTPをinternal networkだけに置いた初回はportが割り当てられず、http専用bridge追加後に疎通しました。その直後のWindows疎通1回はITの読み取り段階で失敗しましたが、当時は詳細分類がなく原因は特定できていません。以後は失敗箇所・例外型だけを表示するようにし、手動再実行と停止/再起動後の再実行はともに成功しました。自動再送や基準緩和は追加していません。

ローカルHTTPは開発専用です。公開APP_ENV=productionのSecure Cookieを弱めていません。Docker Engine 27.5.1のlocalhost公開に関する既知のL2制約は [demo.md](demo.md)に記録し、外部端末からの隔離を保証済みとはしません。React/Vite proxy実機、AWS対象照合・権限・公開投入・資格情報配布、実HTTPS、GitHub CI実行・必須チェック設定は未検証です。

## Reactログイン・一覧工程（2026-09-29）

開始HEADは`f98fef9957f9bd07ab7c3ad5fa3614424f485077`、差分なしでした。frontend/、Node24.21.0の非root/multi-stage Docker、5173同一オリジンproxy、PowerShell起動/検証script、独立したブラウザー検証用Compose、CI workflowを追加しました。既存PHP/Gitleaks workflow・migration・接続先ガードは変更していません。詳細は [frontend.md](frontend.md)です。

| 検証 | 結果 |
| --- | --- |
| 型・lint・build・npm audit | 全て終了コード0、依存監査0件。採用版とpeer条件はfrontend文書 |
| Vitest/jsdomのモック検証 | 33テスト成功。認証状態、古い応答破棄、二重送信、401/419/422/429/通信失敗、ページング、label/focus |
| 隔離実ブラウザー | Chromium + 通常public/index.php + PostgreSQL。A/B/X/Y、URL直接アクセス/再読込、20/21件、タイトルのXSS非実行、390px幅で成功 |
| Windows再実行手順 | scripts/test_frontend.ps1を実行し全検査と隔離実ブラウザー再検証、後片付けまで終了コード0 |
| 既存デモ実ブラウザー | 同じ4人のログイン/一覧/ログアウト成功。前後で業務3表・投入記録の内容を非表示比較して一致。4/4/4/1件を保持 |
| PHP回帰 | 270テスト・3,855アサーション・終了コード0 |
| 誤接続拒否 | DB_HOST=dev-dbをテストへ渡すと接続前に終了コード1、期待どおり拒否 |
| 秘密情報制御 | 隔離repo検査成功。検出時commit拒否・値redaction・既存hook共存・scanner欠落時失敗、HEAD/index不変 |
| 最終確認 | 文書のローカルリンク、新規ファイルの末尾空白、git diff --check / --cached --check、Gitleaks files / staged成功（stagedは0件） |

ビルド出力先の非root権限と、隔離ComposeのDB起動依存を修正して再検証しました。過去のIT読み取り1回失敗は原因未特定のままで、今回の成功によって解消済みとはしません。再発時の安全なログ/状態確認はfrontend文書に記録しています。画面全体のアクセシビリティ、全ブラウザー、登録/詳細、AWS、今回GitHub CI実行・必須チェックは未検証です。commit/pushは実施していません。
