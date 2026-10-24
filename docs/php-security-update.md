# PHP 8.4.27 セキュリティ更新（2026-10-22、24日追検証）

## 2026-10-24の追検証

22日の結果は下記に履歴として保持します。24日の最新DBによる再検査では、同じイメージにPHP High 1件、Nginx High 3件が検出され、検査は終了コード1で停止しました。基準・除外設定は変更していません。

- PHP側：`brotli-libs`のCVE-2026-111497。[Brotli公式1.2.1の修正](https://github.com/google/brotli/releases/tag/v1.2.1)を確認し、開発・本番runtime双方に`brotli-libs>=1.2.1-r0`を指定しました。
- Nginx側：CVE-2026-15176 / CVE-2026-93479 / CVE-2026-97205。[公式修正版1.30.6](https://nginx.org/en/security_advisories.html)へ更新しました。公式とTrivyの重大度に差がありますが、ゲートはTrivyのHigh/Criticalを維持します。
- 公式`nginx:1.30.6-alpine-slim`のindex digestを実取得して`sha256:d9322bba63c6cfc63dc9e86522fd4f95fd4d9020a02b5bced282bbf38b8c3960`へ固定。既知のmusl / musl-utils / zlibも修正版以上を要求し、旧版のままビルド成功しないようにしました。PHP本体の固定digest・8.4.27は維持しています。
- 24日のnpm監査は**High 3 / Moderate 2、終了コード1**。下記の22日の4件に加えて`undici`が検出されました。アプリ依存lockは今回変更していません。この未解消検出により、全検査合格ではありません。

22日の全JSONは`.local/production-reports-history/php8427-20261022/`、24日の修正前失敗は`.local/production-reports-history/php8427-20261024-before-os-fix/`へ保存しました。22日の[証跡JSON](php-8.4.27-verification.json)も履歴として維持します。

修正後の最終2イメージは、24日01:18 UTC更新のTrivy DB（04:49 UTC取得）で**全重大度0件、終了コード0**。SBOMを再生成し、image IDとSHA256の対応を`.local/production-reports/`へ保存しました。Brotliは1.2.1-r0、Nginxは1.30.6-r1、musl/musl-utilsは1.2.6-r3、zlibは1.3.2-r1です。最終イメージの不要物・非root・全レイヤーの既知秘密値検査も成功しました。

更新後のNginx/FPMを使う隔離HTTPS検証は、同じ依頼での登録・担当・コメント・完了、Bの404、ログアウト後401、Cookie/CSRF/no-store、read-onlyで成功（24日04:58 UTC）。CLI/FPMの実版8.4.27、開発CLIと本番php-baseの各270テスト・3,855アサーション、従来の隔離ブラウザー回帰、Composer監査検出0も再確認しました。[24日の非機密証跡](php-8.4.27-verification-20261024.json)に修正前の失敗と修正後の成功、image ID・SBOM hashを対応付けています。フロント型/lint/build/136モックテストはコード・lock未変更の22日の成功、実ブラウザーは24日の再成功として区別します。npm監査の5件は未解消のままです。

## 更新理由と範囲

[PHP公式の2026-10-21告知](https://www.php.net/archive/2026.php)は、CVE-2026-109618 / CVE-2026-109619の修正版として8.4.27を案内しています。PHP-FPMも影響対象です。[リリース案内](https://www.php.net/releases/8_4_27.php)と[変更履歴](https://www.php.net/ChangeLog-8.php#8.4.27)を2026-10-22に確認しました。告知は21日、変更履歴は22日表記です。

開発・PHPUnit用8.4.25と本番FPM用8.4.26のイメージを8.4.27に統一しました。両Dockerfileの拡張ビルドと実行ベースの計4か所を更新し、pdo_pgsql、本番のopcache/mbstringを新しいベースで再コンパイルしました。Composer/npmのlock、アプリ、migration、DB接続先ガードは変更していません。

## 取得した公式イメージ

Docker Hubの[公式PHPイメージ](https://hub.docker.com/_/php)を`docker buildx imagetools inspect`で確認しました。以下はmulti-platform index digestです。今回の実行対象はDocker DesktopのLinux/amd64です。

| 用途 | タグ | 固定digest |
| --- | --- | --- |
| 開発・テスト・Composer実行 | `php:8.4.27-cli-alpine3.24` | `sha256:8d29b132476e5f54633d30bfbfa0b0b7c95a9cb0c308b3175d61351aea73b643` |
| 本番拡張ビルド・FPM | `php:8.4.27-fpm-alpine3.24` | `sha256:8ce34c869fae0f8da2c82d8aeda222f0782e8e5ae2b370fb43cbad84e9e2ea55` |

PHP本体は公式イメージ内でソースビルドされており、TrivyのAlpine/Composer検査だけではPHP本体の修正を保証できません。公式告知・固定digest・実際のCLI/FPMバージョン確認を併用します。

## 2026-10-22の検証記録（履歴）

**PHPイメージ更新・回帰・HTTPS・イメージ検査は完了しました。ただし全検査合格ではなく、npm監査の4件は未解消です。** 作業途中はComposer依存のGitHub取得がタイムアウトしていましたが、既存の成功ビルド内のダウンロード済みアーカイブを再利用して最終ビルドを完了しました。開発のHTTP/フロントコンテナは停止状態を維持しており、通常利用するコンテナへの切替は下記手順で行います。

過去の[本番検証証跡](production-verification.json)は上書きしていません。再検査前の非機密JSONをGit管理外の`.local/production-reports-history/before-php-8.4.27-20261022/`へ退避しました。新しいSBOM・全重大度結果・HTTPS証跡は`.local/production-reports/`、npm監査JSONは`.local/php8427-reports/npm-audit.json`です。[今回の非機密証跡](php-8.4.27-verification.json)に新しいimage ID、SBOMのSHA256、DB更新時刻、検出概要を記録しました。

| 確認 | 今回の結果 |
| --- | --- |
| 公式ベース提供・固定digest | CLI/FPMとも取得確認、Dockerfile各2ステージを更新 |
| 拡張再コンパイル | 開発pdo_pgsql、本番pdo_pgsql/opcache/mbstring成功 |
| 実バージョン | ツール用CLIと本番php-baseのCLI/FPMは8.4.27、本番の3拡張も8.4.27 |
| 最終イメージbuild | 開発用、本番PHP/Nginx、既存検証ツールのbuild成功、終了コード0。Composer validate/install/check-platform-reqs成功。取得済みアーカイブから8.4.27上でlock通りに再インストール |
| PHP全回帰 | 完成したCLIイメージで**270テスト・3,855アサーション、終了コード0**。さらに本番php-base（同じ拡張/runtime library）のCLIでも**270テスト・3,855アサーション、終了コード0** |
| Composer依存監査 | 既存プロキシ経由の`audit --locked --no-interaction`は検出0、終了コード0 |
| フロント型・lint・モック・build | 成功。既存136モックテスト成功。コード/lock変更なし |
| npm依存監査 | **失敗、終了コード1**。High 2 / Moderate 2。Vite 8.3.1、source-map-js、Vitest 5.0.2 / @vitest/mockerに検出。`npm audit fix --force`や基準変更は行っていない |
| 従来実ブラウザー | 更新PHPの隔離APIで認証・登録/閲覧・担当/状態・コメント・順序制御409の回帰成功、終了コード0 |
| 隔離HTTPS | 最終FPM 8.4.27で同一依頼の登録→割当→対応開始→社員/ITコメント→確認待ち→完了、Bの404、logout後401、Secure/HttpOnly/SameSite・CSRF・no-store・SPA URL成功、終了コード0 |
| 最新Trivy DB | 再開後は空の検査キャッシュへ再取得。UpdatedAt `2026-10-22T13:15:26.425424764Z`、DownloadedAt `2026-10-22T14:28:40.423397817Z`。前の07:10更新DBによる途中結果と区別 |
| 最終イメージTrivy / SBOM | 両イメージHigh/Critical 0、終了コード0、SBOM生成成功。PHP全重大度0。NginxはMedium 1 / Unknown 2を残す。除外・基準変更なし |
| イメージ保護 | 非root/read-only、不要ツール・禁止ファイル・全レイヤーの既知秘密値混入検査成功。実行時ログに既知秘密値なし、設定cacheなし。最終imageの環境にproxy設定なし |
| データ保全 | 開発users 4件・依頼5件・コメント4件の内容ハッシュが作業前と一致。読み取り確認後、元の停止状態へ戻した。初期化・再投入・volume削除なし |

npmの対応候補は、監査が示すVite 8.3.5 / Vitest 5.0.5とsource-map-jsの修正版です。PHP更新に混ぜて依存lockを自動変更せず、別単位で公式advisoryと互換性を確認し、型/lint/モック/ビルド/実ブラウザー/監査を再実行する必要があります。検出の一例は[Vitest advisory](https://github.com/advisories/GHSA-qxg6-89mg-rqv8)、[Vite advisory](https://github.com/advisories/GHSA-9jrq-w75r-8gcw)、[source-map-js advisory](https://github.com/advisories/GHSA-68fv-2mgg-jv7q)です。

Nginxの残件は`musl` / `musl-utils`のCVE-2026-107891（Unknown、1.2.6-r2→1.2.6-r3）、`zlib`のCVE-2026-85091（Medium、1.3.2-r0→1.3.2-r1）です。スキャナーの現在の分類を記録したもので、Unknownを安全と判断したものではありません。Nginxベース/対象パッケージ更新と再検査を次の保守単位に残します。

全PHPテストは専用PostgreSQLと実行ごとのschemaで行いました。再開前のテストプロセスは実行環境の停止で結果を回収できなかったため、成功扱いにせず再実行しました。Windows上のbackendをread-only mountする補助試験はI/Oが遅く中断し、完成した開発imageの/appを検証専用Linuxレイヤーへコピーした本番php-baseで全件を実行しました。これは検証専用imageで、配布imageにPHPUnit・Composer・testsを追加していません。本番php-base上のCLI全回帰と、最終FPM経由のHTTPS業務フロー成功は別の結果です。

GitHub API/codeloadへのタイムアウトはWindows/コンテナ双方で再現し、根本原因は未特定です。以前の成功したdependenciesステージの`/tmp/composer/cache/files`だけをBuildKitの専用cacheへ移し、両Dockerfileで`sharing=locked`のcache mountを使用しました。旧PHPバイナリー・旧拡張・ホストvendorの流用ではありません。cacheは最終レイヤーへ含めず、空cacheの新規環境では通常どおり公式配布元へ接続が必要です。旧cache再利用の成功をネットワーク復旧や空cacheからのbuild成功と扱いません。

既存CIは更新Dockerfileを参照するため8.4.27を使いますが、対象commitのGitHub CI成功、AWS経路、公開環境は未検証です。commit/push・AWS操作は行っていません。

## Windowsで既存データを保持して切り替える

Docker Desktopを起動し、リポジトリのルートのPowerShellで実行します。既存`.env`、`.local`、DB volumeをそのまま使用します。以下は既にローカルデモを準備済みの環境用です。

**イメージはビルド済み、開発用コンテナは切替前です。** この作業環境では下記の切替部分だけでも使用できます。別環境での再現はbuildとテストの成功を確認してから切り替えます。ビルド失敗後に古いタグのまま`up`して更新済みとは判断しないでください。

今回の環境ではWindowsからのHTTPSは成功しましたが、コンテナの直接外向き通信はAlpineでTLS/I/Oエラー、Trivyで接続拒否、npmで監査取得失敗となりました。Docker Desktopの既存プロキシ`http://http.docker.internal:3128`をビルド・監査にだけ渡すと接続できました。プロキシ未使用の環境では設定不要です。この値をDockerfileのARG/ENV、Composeのアプリ環境、Gitへ組み込まず、TLS検証も解除しません。[Docker公式のビルド専用proxy引数](https://docs.docker.com/build/building/variables/#proxy-arguments)を使用します。

24日には、このコンテナ専用名をWindowsの`HTTP_PROXY`/`HTTPS_PROXY`に設定すると、Docker CLIのregistry認証が`lookup http.docker.internal: no such host`で失敗しました。新しいPowerShellではホスト側へこの値を設定せず、必要な場合だけ下記の`$DockerBuildArgs`で**ビルド内**へ渡します。これは資格情報を含まないDocker Desktopの既存endpoint専用の例です。資格情報付きproxy URLを引数へ渡さないでください。通常のプロキシ環境は管理者指定に従い、ホスト用とコンテナ用の到達性を分けて確認します。

```powershell
$ErrorActionPreference = 'Stop'
function Invoke-DockerChecked {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw 'Docker failed; stop here.' }
}
$DockerBuildArgs = @()
# この環境でコンテナ内の直接通信だけが失敗する場合に使用：
# $DockerBuildArgs = @('--build-arg', 'HTTP_PROXY=http://http.docker.internal:3128', '--build-arg', 'HTTPS_PROXY=http://http.docker.internal:3128')

# ビルドと専用テストDBの回帰。開発DBへmigration/seedしない。
Invoke-DockerChecked compose --profile tools build @DockerBuildArgs app composer
Invoke-DockerChecked compose --profile test up -d --wait test-db
Invoke-DockerChecked compose --profile test run --rm test
Invoke-DockerChecked compose --profile tools run --rm --no-deps composer audit --locked --no-interaction

# 既存volumeのDBを起動し、HTTPコンテナを新imageに置換。
Invoke-DockerChecked compose up -d --wait dev-db
Invoke-DockerChecked compose --profile http up -d --no-deps --force-recreate http
Invoke-DockerChecked compose --profile http exec -T http php --version
Invoke-DockerChecked compose --profile frontend up -d --no-deps frontend
```

`http://127.0.0.1:5173/login`から確認します。PHPが8.4.27であることを確認してください。資格情報は既存の`.local/credentials.json`を手元だけで参照し、共有ログへ表示しません。開発DBを停止していた場合は先に`Invoke-DockerChecked compose up -d --wait dev-db`を実行します（既存volumeを再使用）。

この環境のComposer監査で直接通信が失敗する場合は、`run`の`composer`より前へ`-e HTTP_PROXY=http://http.docker.internal:3128 -e HTTPS_PROXY=http://http.docker.internal:3128`を追加します。監査の検出と通信失敗は区別し、失敗したまま後続へ進みません。

既に準備済みの本番用隔離HTTPS環境は次の手順です。`setup` / `prepare`の再実行は不要です。証明書は7日有効なので、期間を超えた場合は以下の`renew-tls`を実行します。DB・APP_KEY・アカウントは更新しません。

```powershell
function Invoke-ProductionChecked {
    & python scripts/production.py @args
    if ($LASTEXITCODE -ne 0) { throw 'Production verification failed; stop here.' }
}
Invoke-ProductionChecked build
Invoke-ProductionChecked renew-tls
try {
    Invoke-ProductionChecked test
    Invoke-ProductionChecked scan
} finally {
    Invoke-ProductionChecked stop
}
```

`production.py build`でコンテナ内の取得だけが失敗する場合は、ホスト側proxyを変更せず、`Invoke-DockerChecked build @DockerBuildArgs -f docker/production/Dockerfile --target php -t it-requests-php:production .`、続いて同じコマンドのtarget/tagを`nginx` / `it-requests-nginx:production`としてビルドします。その後`build`を再実行すれば取得済みstageを利用できます。空cacheの新規環境は検証用イメージの取得も必要です。

`scan`の外部通信だけにこのproxyが必要な場合は、別ターミナルで`$env:HTTP_PROXY='http://http.docker.internal:3128'`と`$env:HTTPS_PROXY=$env:HTTP_PROXY`を設定し、`python scripts/production.py scan`を実行して終了コードを確認します。スキャナーへ環境変数名で渡す既存処理を使用します。そのターミナルで`build`は行わず終了後に閉じます。TLS検証は解除しません。

`test`は既存の隔離DBへ検証用依頼を追加します。開発DBは変更しません。手動でHTTPS画面を継続利用する場合は[本番用構成](production.md)の証明書信頼・起動手順に従います。ブラウザー自動検証の信頼設定とWindowsの通常ブラウザーの信頼設定は別です。

フロント全検査は`powershell -NoProfile -File scripts/test_frontend.ps1`です。今回のように監査の通信失敗で停止した場合、プロキシ設定後に`Invoke-DockerChecked compose --profile frontend run --rm --no-deps -e HTTP_PROXY -e HTTPS_PROXY -e NO_PROXY frontend npm run audit`で監査を再実行します。脆弱性検出による失敗は通信失敗と区別し、基準を緩めません。

```powershell
python scripts/secrets.py files
if ($LASTEXITCODE -ne 0) { throw 'Secret scan failed.' }
python scripts/secrets.py staged
if ($LASTEXITCODE -ne 0) { throw 'Staged secret scan failed.' }
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Diff check failed.' }
```

停止だけなら`Invoke-DockerChecked compose --profile http --profile frontend stop http frontend`で既存データを保持できます。`down -v`、volume削除、`migrate:fresh`、再seedは行いません。
