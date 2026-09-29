# FR-09 初期投入とローカルHTTP

## 採用範囲と実装理由

2026-09-29更新。今回採用したのは、架空の社員A/B・IT担当者X/Y、example.testのメール、3種別・4状態の依頼4件とコメントを手動投入するFR-09と、既存12 APIのローカルHTTP起動です。React、AWSへの投入、資格情報の外部配布は対象外です。[要件のAC-16・17](requirements.md)、[DBのOPS-01～03](database.md)、[認証](authentication.md)に対応します。

通常migrationは構造変更のみです。`demo:seed`を明示実行するまで、アプリ起動・HTTP・migrationはデータを投入しません。既存4本のmigrationは変更せず、5本目に管理表`demo_seed_runs`を追加しました。Web APIは増やしていません。

| 単位 | 実装 | 理由 |
| --- | --- | --- |
| 投入管理 | [追加migration](../backend/database/migrations/2026_09_28_000002_create_demo_seed_runs_table.php)、[Artisanコマンド](../backend/app/Console/Commands/DemoSeed.php) | 1 DBに成功記録1件。Webや起動から分離 |
| 安全判定・原子性 | [DemoTarget](../backend/app/Demo/DemoTarget.php)、[DemoSeeder](../backend/app/Demo/DemoSeeder.php) | 設定と実接続を照合し、空DBへの1回だけの投入をtransactionで確定 |
| ローカル資格情報 | [setup_demo.py](../scripts/setup_demo.py) | アカウントごとに暗号学的乱数32 bytesをbase64url化した43文字。既存ファイルを上書きしない |
| 通常HTTP | [compose.yaml](../compose.yaml)のhttp service | PHPの開発用サーバーから通常のpublic/index.phpへ到達。テストrouterを使わない |
| 検証 | [DemoTest](../backend/tests/Demo/DemoTest.php)、[check_local_http.py](../scripts/check_local_http.py) | 専用DBの自動テストと、開発DBを読み取り確認する手動疎通を分離 |

## 投入ガード・排他・再実行

`DEPLOYMENT_PURPOSE=demo`、`APP_DEBUG=false`、`--allow`、`--publication`、`--seed-version=1`が必要です。publication_idは英数字から始まる英数字・`_`・`-`の1～64文字。実装済みのseed_versionは1だけです。`APP_ENV=production`でもこれらを満たす専用demoには投入でき、APP_ENVだけでは可否を決めません。config cacheや接続先を差し替えるURL等は拒否します。

| target | ホスト / port | DB / 接続role | schema・追加条件 |
| --- | --- | --- | --- |
| local | dev-db / 5432 | portfolio_dev / portfolio_dev | public。通常のCompose開発DBだけ |
| test | test-db / 5432 | portfolio_test / portfolio_test | users_test_ + 24桁hex。既存テストガードが作ったschemaとDB識別comment |

さらに実接続のcurrent_database / current_user / session_user / current_schema / port / 非superuserを確認し、inet_server_addrのアドレス部分を許可ホストのDNS解決結果と照合します。ローカルComposeのDNS・設定を信頼する範囲の判定であり、AWS account・RDS ARN・管理roleの実証ではありません。AWS targetは未実装で拒否します。

1. 前提検査後、1 transactionで`demo_seed_runs → users → service_requests → comments`をEXCLUSIVE table lockします。空DB判定の前にlockするため、同時投入が両方「空」と判断できません。HTTP運用中に初回投入する手順にはしません。
2. 同じpublication_id / seed_versionの記録があれば、資格情報ファイルを読み直すこともなくno-op。追記・停止・パスワード・時刻を変更しません。欠損修復や資格情報の復旧機能ではありません。
3. 違う記録、または記録なしで業務3表のいずれかが非空なら非0終了。初回のみ資格情報を検証し、4利用者・4依頼・4コメントと成功記録をまとめてcommitします。
4. 例外時は全行rollback。PostgreSQLの採番はrollbackで戻らないためIDに欠番は生じ得ます。連番の復元や既存行削除で通しません。

lock_timeout=5秒、statement_timeout=10秒で、待機超過は失敗し自動再試行しません。別プロセス・別接続を同期点で制御し、先行commit後に後続がno-opになることを検証しました。公開環境の負荷・全体実行時間の保証ではありません。Web更新の既存users共有lockと競合し得るため、初回投入はHTTP起動前に行います。

社員A/Bに2依頼ずつ割り当て、open / in_progress / waiting_confirmation / completedを1件ずつ、inquiry / bug / improvementを網羅します。完了依頼は「登録 → 依頼者の確認コメント → ITによる完了」の時刻順です。初期状態を履歴に相当するversionとともに作るための管理投入であり、Webで完了後にコメントを追加する処理ではありません。

## Windows PowerShellでの準備・投入

Docker DesktopをLinux containersで起動し、リポジトリルートで1行ずつ実行します。各終了コード0を確認して進みます。資格情報生成はDocker Desktopを使う通常のWindowsユーザーで実行してください。

```powershell
python scripts/setup_local.py
python scripts/setup_demo.py
git check-ignore .local/credentials.json
docker compose build app
docker compose up -d --wait dev-db
docker compose run --rm app php artisan migrate --force
docker compose --profile demo run --rm seed --target=local --publication=local-v1 --seed-version=1 --allow
```

初回は`Demo seed: created.`、同じ条件の再実行は`Demo seed: no-op.`です。既存データがあって拒否された場合、そのDBは保全して止めます。`migrate:fresh`、`down -v`、成功記録だけの削除、既存行削除、制約無効化で通しません。異なるpublicationの開始は別の新規DBを準備する次工程の運用です。

`.env`は接続用、`.local/credentials.json`は4人のログイン用で、どちらもGit管理外です。`.local`はビルドcontextの許可リストにも含まれず、seed serviceだけにread-onlyでmountします。HTTP serviceにはmountしません。コマンド引数・環境変数・共有ログにログインパスワードを渡しません。

手元で資格情報を見る場合だけ、次を自分のエディターで開きます。内容を共有ログ・チャット・スクリーンショットへ貼り付けないでください。

```powershell
notepad .local\credentials.json
```

生成時はWindowsのACL継承を外し、実行ユーザー・SYSTEM・Administratorsに権限を設定します（既存の明示ACLは保持）。POSIXではdirectory 0700 / file 0600です。再生成は既存ファイルを変更しません。ファイル紛失後に新しい値を生成しても、投入済みDBのパスワードは変わりません。Composeはファイルがない場合、空directoryを自動作成せず失敗します。今回の実行ではCodexの制限ユーザーとDocker Desktopのユーザーが異なりmount拒否になったため、確認した利用者SIDだけを追加許可し、通常ユーザーで再実行しました。Everyoneへの許可やchmod 777は使っていません。

## HTTP起動・疎通・保持した停止

```powershell
docker compose --profile http up -d --wait http
python scripts/check_local_http.py
docker compose --profile http stop http dev-db
docker compose --profile http up -d --wait dev-db http
python scripts/check_local_http.py
```

URLは`http://127.0.0.1:8000/api/me`です。未ログインでブラウザーから直接開くとJSONの401が正常です。`/`にReact画面はなく404です。疎通スクリプトはCookieを保持し、4人についてCSRF取得→login→me→一覧・詳細・コメント閲覧→logout→401を確認します。業務データは変更せず、session・試行制限には通常の認証処理が適用されます。短時間に繰り返して429になった場合は60秒の制限窓が過ぎてから再確認し、制限を無効化しません。

PHP開発用サーバーをUID10001で実行し、ホスト公開は127.0.0.1:8000のみ。DBのportはホストへ公開しません。現Docker Desktopではinternal networkだけのHTTP containerでport publishが有効にならなかったため、httpだけをlocal_http bridgeにも接続しました。DBはinternalなdevelopment / testing networkのままです。httpには外向き通信が可能になりますが、公開用ECS/Nginx構成の代替ではありません。[PHP公式の開発サーバーの制約](https://www.php.net/manual/en/features.commandline.webserver.php)を参照してください。

通常起動は`public/index.php`だけを通し、tests/http-router.php・時計操作・障害注入routeは使いません。テスト用DB_SCHEMAはガード済みの隔離HTTPプロセスでのみ設定し、通常Composeはpublicのままです。既存TestDatabaseGuardは外部からのDB_SCHEMA上書きを引き続き拒否します。

ローカルHTTPのAPP_ENV=localではSecure=false、公開用APP_ENV=productionではSecure=trueを強制する既存設定を維持します。CSRFを無効化せず、広いCORSも追加していません。確認環境のDocker Engine 27.5.1では、28未満のlocalhost publishに同一L2から到達し得る既知の制約があるため、信頼できるローカル環境専用です。bind設定の確認を外部端末からの到達不能の証明とは扱いません。信頼できないネットワークで利用する前にEngine更新等を確認します。[Docker公式のlocalhost公開に関する注意](https://docs.docker.com/engine/network/port-publishing/)

停止は上記`stop`でデータを保持します。`down`でもnamed volumeは保持されますが、他profileの稼働serviceも停止し得ます。通常利用ではstop対象を明記し、`-v`は付けません。ソース変更後は再buildし、httpを再作成します。migrationの再適用・同じseed再実行でリセットしません。

## Vite接続（ログイン・一覧工程で実装）

ブラウザーは127.0.0.1:5173へ統一します。Docker内のViteから`http://http:8000`へproxyし、GET /loginはReact、POST /loginはLaravelです。相対URL・Cookie・X-XSRF-TOKENを使い、ホスト名をlocalhostと127.0.0.1で混ぜません。ブラウザーから別portへ直接fetchするCORS方式は追加していません。[今回の起動・検証手順](frontend.md)では既存DB・資格情報を使い、seedやmigrationを再実行しません。[Vite公式server.proxy](https://vite.dev/config/server-options.html#server-proxy)

## 検証・未検証の境界

専用test-db・ランダムschemaで実施する自動テストは次のとおりです。開発DBを初期化するテストはありません。

```powershell
python scripts/test_demo_setup.py
docker compose --profile test up -d --wait test-db
docker compose --profile test run --rm test php vendor/bin/phpunit --testsuite Demo
docker compose --profile test run --rm test
python scripts/secrets.py files
python scripts/secrets.py staged
```

| FR / AC・境界 | 検証 |
| --- | --- |
| FR-09 / AC-16 | 4人・3種別4状態・所有分離・完了前コメント、local条件・production実行設定、no-opで追記/停止/パスワード/資格情報を保全 |
| FR-09 / AC-17 | 非demo・明示許可なし・誤targetをlocal/production双方で拒否。設定/実接続不一致、非空/異なる記録、不正資格情報、debug有効を拒否 |
| 原子性・同時投入 | INSERT後例外で4表rollback。別process/DB接続、明示同期点とpg_stat_activityのlock待機確認後に解放しcreated/no-op・記録1件 |
| API-01～12 | 通常public/index.phpと実Cookieで全12件へ到達。A/Bの本人分・他人404、IT全件、logout後401、テスト専用route404 |
| 既存回帰 | Users / Authentication / Requests / Workflow / Commentsを維持。具体的な実行結果は[開発記録](development.md) |

テスト用資格情報は専用schemaと一時ファイルに生成し、Windowsのローカル用ファイルとも公開配布用資格情報とも分離します。CIはDemo suiteと全suiteを追加済みで、既存Gitleaks・必須失敗判定を維持します。対象コミット・実行URL・結果を確認していないためGitHub成功とは記載しません。必須チェック設定も未確認です。

AWS対象照合・管理/公開DB role分離、公開投入・資格情報配布/失効、実HTTPS・CloudFront、登録/詳細画面、外部端末からのネットワーク隔離は未検証です。ログイン/一覧画面・一覧タイトルのXSS非実行・Vite proxyは後続の [frontend.md](frontend.md)で検証しました。
