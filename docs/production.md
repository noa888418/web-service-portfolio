# 本番用イメージと隔離HTTPS統合検証

## 採用範囲と構成

2026-10-01、既存の12 API・認証・4画面を維持し、NginxでReact成果物を配信しPHP-FPMでLaravelを実行する2イメージを追加しました。開始HEADは`4da63475347e301d5cf4b99914ead81ab7fd2645`、開始時の未コミット差分はありませんでした。AWS、公開用seed、レジストリpush、commit・pushは実施していません。

| 目的 | ファイル |
| --- | --- |
| 配布対象2イメージ | [Dockerfile](../docker/production/Dockerfile)、同ディレクトリの設定・entrypoint、専用dockerignore |
| 隔離した起動・DB | [compose.production-test.yaml](../compose.production-test.yaml) |
| 証明書・データ準備・実ブラウザー | [docker/verification](../docker/verification/)（配布対象には含めない） |
| Windows/Linux共通の操作・検査 | [production.py](../scripts/production.py)、[PowerShell検証](../scripts/test_production.ps1) |
| CI | [production.yml](../.github/workflows/production.yml)。既存のusers・frontend・secrets workflowは維持 |

ローカルの経路は`Chromium → HTTPS localhost:8443 → Nginx → FastCGI php:9000 → PHP-FPM → PostgreSQL test-db:5432`です。ホストへのbindは`127.0.0.1:8443`だけで、DBとFPMは公開しません。Composeの別コンテナ間はサービスDNSを使用します。ECS awsvpcの同一タスクではネットワークを共有するため、将来は`PHP_UPSTREAM=127.0.0.1:9000`に変更します。このECS経路は未検証です。

検証プロジェクト名は`it-requests-production-tests`。開発用の`it-requests-local`、従来のブラウザー用`it-requests-browser-tests`と分離しています。DBのnamed volume、ランダム資格情報、schemaはこの検証専用です。テスト実行は隔離DBに依頼・コメントを追加します。開発DBへの自動登録はありません。

## 採用版と理由

公式資料確認日：**2026-10-01**。本番用PHPは8.4系内の修正版へ更新しました。既存開発・PHPUnit用PHP8.4.25とその270テストは維持し、本番PHP8.4.26は実HTTPS/FPMの通し検証で確認します。270テスト全部を8.4.26で実行したという意味ではありません。

| 対象 | 採用・理由 | 公式資料 |
| --- | --- | --- |
| PHP-FPM | 8.4.26 / Alpine3.24。Laravel13の既存lockと同じPHP8.4系、Composer platform要件をビルドで検査。FPM公式イメージを優先 | [PHP対応版](https://www.php.net/supported-versions.php)、[リリース](https://www.php.net/releases/)、[公式イメージ](https://hub.docker.com/_/php)、[FPM設定](https://www.php.net/manual/en/install.fpm.configuration.php) |
| Nginx | 1.30.5 alpine-slim。安定系列で静的配信・FastCGIに必要な構成を使用 | [公式配布](https://nginx.org/en/download.html)、[FastCGI仕様](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html) |
| Alpine | 最終イメージの検出版3.24.2。mainの保守期限2028-06-01。small imageだけで判断せず起動・依存を検査 | [保守予定](https://alpinelinux.org/releases/) |
| Node / Composer | ビルド専用Node24.21.0 / Composer2.10.3。既存lock・選定を維持し最終イメージにはコピーしない | [Node公式リリース](https://nodejs.org/en/blog/release/v24.21.0)、[Composer変更履歴](https://getcomposer.org/changelog/2.10.3) |
| Laravel / React | 13.32.0 / 19.3.0。依存lockは変更なし | [既存選定](development.md)、[フロント選定](frontend.md) |
| Trivy | 0.74.0。最終image archiveからOS・Composer・npmの構成要素、脆弱性JSON、CycloneDX SBOMを同じツールで扱えるため採用 | [公式release](https://github.com/aquasecurity/trivy/releases/tag/v0.74.0)、[image検査](https://trivy.dev/docs/latest/target/container_image/)、[SBOM](https://trivy.dev/docs/latest/supply-chain/sbom/) |
| 検証専用 | Playwright1.63.0、OpenSSL・NSS certutil。CAを検証ブラウザーだけに信頼させる | [Chromiumの証明書管理](https://chromium.googlesource.com/chromium/src/+/HEAD/docs/linux/cert_management.md) |

Dockerfileのベースは以下のmanifest digestで固定しています（タグだけの追従ではありません）。apkの追加実行時ライブラリーは保守repositoryから取得し、最終image IDとSBOMに確定版を記録します。完全なbit再現性を保証するものではなく、再ビルドごとに再検査します。

| ベース/検査ツール | sha256 |
| --- | --- |
| php:8.4.26-fpm-alpine3.24 | `78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a` |
| nginx:1.30.5-alpine-slim | `32463212baf0e7d91aded2e9b843a4f2b9e017804b8c9d5bae7b51dcef64389c` |
| node:24.21.0-alpine | `ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1` |
| composer:2 | `a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b` |
| aquasec/trivy:0.74.0 | `62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969` |

## 最終イメージと書込領域

PHPは拡張ビルド・Composer依存解決・実行を分けます。Composerは`--no-dev --no-scripts`でlockから導入し、`check-platform-reqs --no-dev`も実行します。pdo_pgsql / mbstring / OPcacheの拡張とlibpq / onigurumaを用意し、ctype / curl / dom / fileinfo / openssl / xml等の必須拡張も有効なことを確認しました。公式PHPに含まれるSQLite等の拡張は、独自のPHP全体ビルドで削ることはしていません。DB接続は既存pgsql設定だけを使います。

Nginxは別stageの`npm ci`・`npm run build`成果物だけを配信します。コンパイラ、Node/npm、Composer、PHPUnit、Chromiumは最終イメージの実行物に含めません。PHPのvendor、Nginxの本番npmメタデータは検査対象です。Composer lockのrequire-devやfrontend lockのdev記載は依存情報であり、実行ツールの同梱ではありません。

Trivyのimage検査はnpm lockだけではbundle内のReactを列挙しません。[公式の検査対象の違い](https://trivy.dev/docs/latest/coverage/language/nodejs/)に従い、ビルドで実際に解決した本番依存のpackage.jsonだけを`/usr/share/portfolio-inventory/node_modules`へコピーします。React / React DOM / schedulerがSBOMに含まれなければ検査を失敗させます。メタデータはweb rootの外で、Nodeやnode_modulesのコードを配布する仕組みではありません。

両イメージのUSERは10001:10001です。検証ComposeはWindowsでは同UID、Linuxでは実行者の非root UID/GIDに合わせ、bind mountの私有ファイルを読めるようにします。PG公式イメージの初期化・volume管理は従来どおりで、PGへの任意user指定・chmod777は追加しません。

| 書込先 | 理由・扱い |
| --- | --- |
| PHP `/app/bootstrap/cache` | Laravelのpackage/service manifest。tmpfs・実行UID所有・0700。設定キャッシュは作らない |
| PHP `/app/storage` | frameworkの一時ファイル等。tmpfs・0700。session/cache本体はPostgreSQL |
| PHP `/tmp` | PHPの実行時一時領域。tmpfs |
| Nginx `/tmp` | pid、生成した非機密upstream設定、各temp directory。tmpfs・0700 |
| PostgreSQL専用volume | 隔離検証データの永続化。stop/restartで保持 |

PHP/Nginxはread_only、cap_drop ALL、no-new-privilegesで起動し、ルートへの書込が失敗することを確認します。FPMは2 worker、terminate timeout30秒、Nginx FastCGI read timeout40秒です。負荷時の応答時間、worker kill時の後処理、AWS全経路のtimeout上限は未検証です。これらの数値を「要求全体が確実に30秒以内」とは扱いません。

`.env`、`.local`、資格情報、backend/tests、テストrouterをbuild contextから除外します。必要なソースを明示COPYし、実値は実行時env_fileから注入します。ビルド引数やconfig cacheには入れません。今回config cacheを採用せず、将来採用する場合も注入後の私有tmpfsで生成し、imageや共有volumeに保存しない設計・検証が必要です。Docker管理権限を持つ人は実行時環境変数を読めるため、ホスト権限自体の保護は別途必要です。

entrypointはproduction/debug=false/必須値を検査してFPMを起動するだけです。migration、seed、migrate:freshは起動時に実行しません。`/healthz`はFPM pingの非機密応答です。DBのreadinessや業務処理成功の保証には使いません。

## 配信・HTTPS・認証

- GET `/login`はSPA、POST `/login`はnamed location経由でメソッド・bodyを保って固定の`/app/public/index.php`へ渡します。`/api/*`、logout、csrf-cookieもPHPへ渡し、エラーをindex.htmlや200に置き換えません。任意のPHP、dotfile、存在しないassetは404。web rootには成果物だけを置き、未知の画面パスはSPA側404になります。
- API/認証応答はNginx側のエラーを含め`private, no-store`。index.htmlもno-store、ハッシュ付きassetsだけimmutableです。FastCGI cacheは使用しません。
- 詳細の`comment_page`を正の整数として画面からAPIの`page`に変換します。ページ操作はURLをreplaceし、同一依頼の下書きをメモリーで維持します。直接表示・再読込時も指定ページを取得します。本文や資格情報はURLへ追加しません。
- ローカルHTTPSはNginx自身が終端し、その実際の`$https`/`$scheme`/`$remote_addr`をFPMに渡します。外部入力のX-Forwarded-* / Forwardedを空にし、LaravelのTrustProxies無効化を維持します。IP枠は検証経路の送信元単位です。AWSのALB経由の実IP・HTTPS判定は別設計が必要です。
- APP_ENV=production / APP_DEBUG=falseを使用し、Secure強制を緩めません。session CookieはSecure・HttpOnly・SameSite=Lax、XSRF CookieはJSが読むためHttpOnlyではなくSecure・Laxです。CSRFなし/不一致419、正しい値で更新成功、logout後の旧Cookie401を実Chromiumで確認します。
- CAと秘密鍵は`.local/production`に生成し、7日で失効。公開CA証明書だけを使って隔離ChromiumのNSS storeに信頼を設定します。`ignoreHTTPSErrors`や証明書エラー無視flagは使いません。Windowsの信頼storeは変更しません。ホストの普段のブラウザーにはこのCAを登録していないため、単にURLを開くと信頼エラーになります。検証は下記のコンテナ内ブラウザーで行います。
- CA秘密鍵はNginxへ渡さず、server key/certだけをread-only mountします。資格情報はprepareと検証ブラウザーだけへ渡し、Nginx/HTTPソースへ取り込みません。

Nginxアクセスログはmethod/status/timeだけです。URI・query・Cookie・header・bodyを記録しません。Nginxの実行中error logはraw request URLを含み得るため抑止し、起動失敗のstderr・healthcheckとLaravelの例外クラス名ログで切り分けます。診断情報が少なくなる制約があり、公開前に安全な監視を設計します。FPM worker stderrは収集しますが入力値を出すコードを追加しません。検証時は実行時秘密値がログにないことも確認します。

## Windowsからの再実行

前提はDocker DesktopのLinux containers、Docker Compose、Python3.10以上、PowerShell。今回Docker27.5.1 / Compose2.32.4で検証しました。リポジトリルートで、初回だけ以下を実行します。setupは既存資格情報を上書きせず拒否、prepareは既に準備済みなら拒否します。

```powershell
$ErrorActionPreference = 'Stop'
& .\scripts\test_production.ps1 -Prepare
if (-not $?) { throw '本番構成の初回検証に失敗しました' }
```

この作業環境は準備済みです。既に`.local/production/runtime.env`とschemaがある環境では`-Prepare`を付けません。

```powershell
$ErrorActionPreference = 'Stop'
& .\scripts\test_production.ps1
if (-not $?) { throw '本番構成の検証に失敗しました' }
```

scriptはbuild → HTTPS通し検証 → image検査/SBOMを順に行い、各終了コードが非0なら停止します。最後に専用Composeをstopし、DB/資格情報は保持します。個別操作は次のとおりです。

```powershell
$ErrorActionPreference = 'Stop'
function Invoke-Production {
    & python scripts/production.py @args
    if ($LASTEXITCODE -ne 0) { throw 'Production command failed' }
}
Invoke-Production up       # 準備済みDBを保持して起動。入口 https://localhost:8443/login
try {
    Invoke-Production test # 信頼済み隔離ブラウザーを起動して検証
    Invoke-Production scan # 最終イメージ2個、SBOM、全重大度結果
} finally {
    Invoke-Production stop
}
```

7日を超えた場合は`Invoke-Production renew-tls`、続いて`Invoke-Production test`と`Invoke-Production stop`を実行します。renew-tlsはNginxを止めて検証用CA/証明書/鍵だけを更新し、DB・APP_KEY・資格情報を変更しません。古いCAをWindowsの信頼storeから削除する操作は不要です（登録していません）。既存volumeや`.local`を削除して通す手順はありません。

準備を個別に行う初回は`build` → `setup` → `prepare` → `test`です。prepareは元のTestDatabaseGuardでenv、DB名/role、サーバー側marker、非superuserを確認し、新しい`users_test_*` schemaだけへ通常migrationを実行します。その後production設定、demo用途、実DB照合、明示許可、publication_id/seed_version、空DB/投入記録の既存DemoSeederガードを満たして投入します。schema名を渡す検証wrapperはマウントファイルであり配布イメージには入りません。通常HTTPは常にpublic/index.phpです。

準備失敗時は出力された段階で停止します。失敗時に新規schemaが残る場合があり、成功記録や資格情報の不整合を無視して繰り返し投入しません。環境値とガードを確認し、誤ったDBへ接続するための設定変更・ガード解除はしません。

今回の資格情報は`.local/production/credentials.json`で、従来の開発demoの`.local/credentials.json`とは別です。必要ならVS Codeで私有ファイルを手元だけで確認できます。値をコマンド引数、共有ログ、CI artifact、画像/traceへ出力しないでください。公開配布用の資格情報ではありません。`.local`のGit除外とビルド除外を維持します。

失敗の切り分けは`docker compose -f compose.production-test.yaml ps`と同composeの`logs --tail 30 nginx php`を、各終了コードを確認して実行します。`docker inspect`全体や展開済み`compose config`は秘密値を表示するので共有しません。APIの未ログイン401は起動失敗ではありません。以前の原因未特定のHTTP疎通失敗が解消済みという主張はせず、今回の経路・日時の成功として記録します。

従来Vite/HTTPの回帰中に、再読み込み後のme401から画面待機がタイムアウトする試行もありました。試験のログイン画面再読込・利用者切替は、表示された見出し/通知だけでなくCSRF準備完了を同期点にしました。失敗時にはpath（queryなし）・method・statusだけを記録し、Cookie/本文/資格情報を出しません。匿名session要求が重なる可能性を試験側で除きましたが、過去の疎通失敗との同一原因は未確定です。同期前の任意の急な操作順すべてを検証済みとはしません。

## イメージ検査と証跡

配布対象は`it-requests-php:production`と`it-requests-nginx:production`。検証ツール用imageを配布対象の代わりに検査しません。実行したimage IDからdocker saveし、Trivyはそのarchiveを読みます。Docker socketを検査コンテナへ渡しません。

**Critical/Highが1件でもある、scanner/DB取得/JSON解析/必要inventoryの確認が失敗する場合は非0**です。未修正の脆弱性を一括除外せず、severity全件をJSONに残します。Trivy呼出しのexit-code 0は全件reportを得るためで、PythonがHIGH/CRITICAL数を評価して必ず失敗させます。例外ファイル・continue-on-errorは追加していません。

`.local/production-reports/`には各imageの`*-evidence.json`、`*-vulnerabilities.json`、`*-sbom.cdx.json`、実HTTPSの`integration-evidence.json`とimage archiveが残ります。evidenceにはUTC日時、image ID / repo digest、platform、Trivy pin、脆弱性DB更新日時、全重大度件数、SBOM SHA256、対象種類を保存します。CI artifactはJSONだけを7日保持し、image archive・資格情報・鍵・browser traceはアップロードしません。再検査は同名reportを更新するので、比較が必要なら非機密JSONだけを別途保存します。

検査範囲はAlpine登録パッケージ・PHP vendorのComposer依存・bundleの本番npm依存です。OSとLaravel/Sanctum、React/React DOM/schedulerがSBOMにあることを確認します。公式PHP image内のソースビルドされたPHP本体はapkパッケージではなく、今回のTrivyのOS/Composer検出にPHP本体のCVE網羅性はありません。公式保守版と更新履歴の確認を併用し、公開前にも再確認します。bundleへの変換後の全コードや未知脆弱性の不存在を保証しません。Trivy0.74のAlpine3.24 EOL一覧に関する警告は公式保守表と照合し、OS3.24のpackage検査実行と、保守期限の別確認を区別しました。

全レイヤー・image設定を読み、実行時APP_KEY/DBパスワード/検証アカウントパスワード/生成秘密鍵の混入を検査します。禁止ファイル、root USER、不要実行ツールも失敗条件です。これは任意の未知秘密値を完全に識別する検査ではないため、既存のGitleaksと許可リスト方式のbuild contextを併用します。

## 検証結果と公開前に残る作業

2026-10-01 Windows + Docker Linux/amd64で確認。開始HEAD上の未コミット変更を検証した結果で、GitHub CIの実行成功は未確認です。対象image/SBOMの対応は[検証証跡](production-verification.json)に記録します。

| 実行 | 結果 |
| --- | --- |
| 本番2image build / platform要件 | 成功。Laravel・npm lockは変更なし |
| 通常FPM + Nginx + 信頼済みHTTPS | 同一依頼で社員A登録→IT割当/対応開始→社員/ITコメント→確認待ち→完了→社員閲覧/追加409、Bの404、logout後401/旧Cookie401。成功 |
| 配信経路 | GET login、new、詳細、comment_page=1/2の直接表示・再読込、JSON401/404/419、private path404、no-store、HTML風コメント非実行。成功 |
| 実行設定 | production/debug=false、Secure/HttpOnly/SameSite、read-only書込拒否、非root、DB/FPM非公開、秘密値ログ不在。成功 |
| 既存PHP回帰 | **270テスト・3,855アサーション、終了コード0**。従来のPHP8.4.25 + 実PG。既存並行処理・ガードを維持 |
| フロント | **136モックテスト**（既存129 + URL関連7）、型/lint/build/audit成功。依存監査0件 |
| 従来の隔離ブラウザー | 最終再実行は全項目成功・終了コード0。従来HTTP経路のログイン・登録・担当/状態・コメント・順序制御409の回帰。再読込の旧期待値は、comment_page契約に合わせURL・末尾ページの2件保持を検証。CSRF準備の同期補強と途中失敗は上記に記録。本番通し試験とは別 |
| 最終image検査 | PHP/NginxともCritical/High/Medium/Low/Unknown検出0。OSと本番言語依存を確認しSBOM生成。初回のWindows文字コードによるreport読取り失敗を修正して再検査 |
| データ保全 | 開発業務4表の内容を作業前後のfingerprintで比較。初期化・再投入・volume削除なし |
| 文書・秘密情報 | ローカル参照リンク、作業ツリー/ステージ済み両方のgit diff --check、LF指定、PowerShell構文、Gitleaks files/staged成功。資格情報・秘密鍵・image archiveはGit除外 |

公開前には(1) CloudFront/ALBの転送・cache/実IP/HTTPS判定とRDS TLS verify-full、(2) ECSのread-only書込volume・UID・healthcheck・worker停止/DB rollback・負荷とtimeout、(3) GitHub上の対象commit/URL/結果、必須チェック、公開資格情報・監視/保存期限・OIDC/IaCを確認します。今回のローカルHTTPS成功をAWS経路の検証済みとは扱いません。順番を制御した画面通し試験と、既存バックエンド並行HTTP/DB試験も区別します。
