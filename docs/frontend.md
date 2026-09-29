# React基盤・ログイン・依頼一覧

## 今回の採用範囲（2026-09-29）

SCR-01ログイン、SCR-02共通依頼一覧とログアウトを実装しました。FR-01 / FR-02 / FR-04、AC-01～03・06～09・13・18のうち該当する画面の範囲です。SCR-03登録・SCR-04詳細、AWS・Terraformは未実装です。登録・詳細へのリンクは置きません。[画面案](screens.md)、[API契約](api.md)、[認証](authentication.md)、[既存demo](demo.md)を参照してください。

作業開始時HEADは`f98fef9957f9bd07ab7c3ad5fa3614424f485077`で、差分はありませんでした。開発DBは4利用者・4依頼・4コメント・投入記録1件を保持しています。今回migration・seed・DB初期化・開発volume削除は実行していません。ブラウザー疎通前後で業務3表と投入記録の内容を値非表示で比較し、一致しました。session・試行制限は通常の認証動作として更新されます。

## 採用版・公式資料

確認日：2026-09-29。直接依存はexact版、推移依存は[package-lock.json](../frontend/package-lock.json)、Dockerはtagとdigestで固定しました。`npm ci`とengine-strictで不一致を失敗させます。ホストはNode22.17.1 / npm10.9.2が存在しましたが、古いpatchを基準にせずDocker内で揃え、ホストのインストールを変更していません。

| 対象 | 採用版・理由 | 公式確認先 |
| --- | --- | --- |
| Node.js | 24.21.0。Active LTS系、Vite / Vitest / jsdomの要求を満たす。26 CurrentよりLTSを優先 | [リリース](https://nodejs.org/en/blog/release/v24.21.0)、[保守予定](https://github.com/nodejs/Release) |
| React / React DOM | 19.3.0で同一版。公式安定リリース。SSR・Server Componentsは使わずSPA | [公式リリース一覧](https://github.com/react/react/releases) |
| Vite / React plugin | 8.3.1 / 6.1.1。pluginのVite8要件と整合。Node20.19+ / 22.12+の要求を24で満たす | [Vite8](https://vite.dev/blog/announcing-vite8)、[対応版](https://vite.dev/releases)、[server設定](https://vite.dev/config/server-options) |
| TypeScript | 6.0.3。確認時latestは7.0.2だがtypescript-eslint8.71.0のpeer範囲は`>=4.8.4 <6.1.0`のため6.0系を採用。LTS保証という意味ではない | [TypeScript6](https://www.typescriptlang.org/docs/handbook/release-notes/typescript-6-0.html)、[typescript-eslintの対応方針](https://typescript-eslint.io/users/dependency-versions/) |
| ESLint / typescript-eslint | 10.11.0 / 8.71.0。Node24、ESLint10・TS6対応peerをnpm公式配布metadataで確認 | [ESLint導入条件](https://eslint.org/docs/latest/use/getting-started)、[typescript-eslint](https://typescript-eslint.io/users/dependency-versions/) |
| Vitest / jsdom | 5.0.2 / 30.1.1。Vitest5はNode22.12+、jsdomは24系なら24.15+、採用Nodeは両方を満たす | [Vitest](https://vitest.dev/guide/)、[jsdom](https://github.com/jsdom/jsdom) |
| Playwright | 1.63.0。npm packageと公式browser imageを同一版に固定。実Chromiumを使用 | [Docker実行](https://playwright.dev/docs/docker) |

関連するTesting Library、型定義も[package.json](../frontend/package.json)で固定し、インストール時にpeer整合を検査しました。React概要ページの表示だけに頼らず、公式リリースとnpm配布metadataの19.3.0を確認しました。サポート状況は将来変わるため、更新時にpeer・公式保守情報・監査を再確認します。依存監査はdevDependenciesも含め`npm audit --audit-level=low`、検出/接続失敗は非0です。

アプリ用イメージはNode Alpineを使ったmulti-stage・非root UID1000です。Vite自体は開発ツールなので開発依存を含み、AWS公開用イメージではありません。実ブラウザー用の重い公式Playwrightイメージはテスト専用・非root UID10001で、同梱Node24.20.0を公式glibc版24.21.0バイナリに揃えました。Chromiumのコンテナ実行はPlaywright既定のsandbox無効構成で、管理下のローカルUI検証に限定します。任意の外部サイトを巡回する基盤としては扱いません。

## 接続と画面状態の設計

- ブラウザーは`http://127.0.0.1:5173`だけを使用。Compose内のViteは0.0.0.0:5173で待受け、ホスト側を127.0.0.1にbindします。内部proxy先は`http://http:8000`。コンテナ内の127.0.0.1をPHPと誤認しません。
- 相対URLの`/api/*`、`/sanctum/*`、POST `/login`、`/logout`をproxy。GET /loginはindex.htmlへ渡し、/requestsもSPA fallbackです。直接アクセス・再読込を実ブラウザーで確認しました。ViteのCORSはfalse、任意host/任意proxy targetを許可せず、root .envをloadしません。
- [api.ts](../frontend/src/api.ts)はCookieをsame-originで送り、XSRF-TOKENをURL decodeしてPOSTのX-XSRF-TOKENに設定します。CSRF取得→login→meの順で、本人情報を確認する前に保護本文を出しません。更新の自動再送はありません。
- [session.ts](../frontend/src/session.ts)は境界ごとに通信の世代を更新し、AbortControllerと応答の世代照合を併用します。abortだけに依存せず、前利用者や前ページの遅い成功/失敗が現在表示を上書きしないようにします。パスワードは送信時に入力欄から消去し、永続ストレージに保存しません。
- 401 / 419、logout開始、非表示タブへの移行で本人・一覧を消去。タブ復帰・ブラウザーの戻る/進む・BFCache復帰はmeを再確認します。期限の最終判定はサーバーであり、画面はAPIの失効応答を受けて処理します。
- ログイン401は共通資格情報エラー、422は既知の項目エラー、429はRetry-Afterの残秒と送信不可、419は再入力。network/5xxは結果不明としてme確認へ進み、POSTを再送しません。logout失敗も保護表示を消しますが「ログアウトしました」と表示しません。meでログイン中と確認した後に利用者が明示再操作します。
- `POST /login`・logoutの同時操作はメモリー上のin-flight guardで拒否。複数画面の認可をこのguardで代替しません。サーバーのCSRF・試行制限・scope・session transactionは変更していません。開発proxy経由ではLaravelのREMOTE_ADDRはproxyとなり、同じ開発proxyのIP枠を共有します。任意X-Forwarded-Forを信頼する変更は行いません。
- 一覧はサーバーの順序のまま20件、日時/ID降順。ID・タイトル・種別・依頼者・担当者・状態・JST日時を表示し、未担当は設計どおり「未割当」。社員/ITは同一画面で見出しだけを変え、取得範囲・件数はサーバー認可に従います。
- 読込・0件・範囲外ページ・失敗を分け、失敗時に古い行を表示しません。422等の上流本文を無条件に描画せず、安全な固定文言へ変換します。タイトル等はReactのテキストで表示し、innerHTMLを使いません。
- visible label、aria-invalid/describedby、エラー要約へのfocus、見出しへのfocus、native button、skip link、JST表記、日本語の状態文字列を使用。390pxでは行をラベル付き縦配置にします。公開終了日時はローカル専用で未設定なので、日時を捏造せず「外部公開していません」と表示します。

登録・詳細リンク、一般登録、remember me、検索/フィルター、UIからのseed・停止機能は追加していません。公開HTTPSのSecure Cookieは既存のproduction強制を維持し、今回のローカルHTTP設定と区別します。

## Windows PowerShellで起動・確認・停止

前提はDocker Desktop（Linux containers）、既存の.envと投入済み開発DBです。この工程では[過去の初期投入手順](demo.md)を再実行しません。次のscriptは各外部コマンドの終了コードを検査し、失敗したらthrowで停止します。

```powershell
& .\scripts\start_frontend.ps1
```

`http://127.0.0.1:5173/login`を開きます。資格情報は自分の端末のエディターでだけ確認します。値をコマンド引数・VITE_変数・ソース・共有ログへ渡しません。

```powershell
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath .local\credentials.json -PathType Leaf)) { throw '既存のローカル資格情報がありません。再生成・再投入せず確認してください。' }
notepad .local\credentials.json
if ($LASTEXITCODE -ne 0) { throw 'エディターの起動に失敗しました。' }
```

社員A→本人依頼、ログアウト→社員B→Aが見えないこと、ログアウト→IT担当者→A/B両方、最後にログアウトを確認します。GET /login、/requestsの直接アクセス・更新も確認できます。未ログインの`/api/me`の401は正常です。資格情報ファイルをfrontend image・Vite・ブラウザー配信へ取り込みません。実デモ疎通の自動化だけがprivateファイルをread-onlyで専用ブラウザーdriverへ渡します。

ソースsrcはread-only bind mountでViteへ反映します。package/config/Dockerfile変更時は起動scriptで再buildします。停止・再開は対象を明示し、volume削除・DB初期化をしません。

```powershell
$ErrorActionPreference = 'Stop'
docker compose --profile frontend --profile http stop frontend http dev-db
if ($LASTEXITCODE -ne 0) { throw '停止に失敗しました。' }
# 再開する場合
docker compose --profile frontend --profile http up -d --wait http frontend
if ($LASTEXITCODE -ne 0) { throw '再開に失敗しました。' }
```

## 再実行する検査

フロントエンド全検査と隔離実ブラウザー検証：

```powershell
& .\scripts\test_frontend.ps1
```

型検査・lint・33件のモックテスト・build・auditのどれかが失敗したら停止します。次に別Composeプロジェクトのtmpfs PostgreSQLへ既存ガード→random schema→migration→テスト用seedと追加19依頼を用意し、通常public/index.php・Vite・実Chromiumを接続します。開発DBへ追加データを入れません。最後にそのテストプロジェクトだけdownし、`-v`は使いません。実行時資格情報は専用tmpfs volume内の0600ファイルに置き、driver終了時に削除、trace/video/screenshotやHTML reportへ出力しません。テストファイルに固定配布パスワードはありません。

既存PHP回帰・秘密情報検査は次の通りです。各失敗で停止します。

```powershell
$ErrorActionPreference = 'Stop'
docker compose --profile test run --rm test
if ($LASTEXITCODE -ne 0) { throw 'PHP回帰テストに失敗しました。' }
python -I scripts/test_secret_controls.py
if ($LASTEXITCODE -ne 0) { throw '秘密情報制御の隔離検証に失敗しました。' }
python scripts/secrets.py files
if ($LASTEXITCODE -ne 0) { throw '秘密情報検査に失敗しました。' }
python scripts/secrets.py staged
if ($LASTEXITCODE -ne 0) { throw 'ステージ内容の検査に失敗しました。' }
```

## 検証結果と残る範囲

| 種類 | 今回の結果 |
| --- | --- |
| 型 / lint / build / npm audit | 成功。監査はdev依存を含め脆弱性0件、警告許容による成功化なし |
| Vitest + jsdom + モックAPI | 33件成功。認証前の非表示、エラー種別、429境界、二重POST防止、logout不明、世代違いの成功/失敗破棄、ページング、空/範囲外、JST、テキスト表示、label/focus |
| 実Chromium + 隔離実Laravel/PG | A/B/X/Yの同一ブラウザー切替・閲覧範囲・logout後401、直接URL/再読込、20件+次ページ1件、HTML/script風タイトルの文字表示と非実行、390px幅の横はみ出しなし |
| 実Chromium + 既存ローカルdemo | 4人のログイン・所有範囲・IT全体・logout・直接URL/再読込成功。業務3表と投入記録の内容は前後一致。資格情報の値をログへ出さず、ファイル変更なし |
| バックエンド回帰 | **270テスト・3,855アサーション・終了コード0**。既存users・認証・依頼・workflow・comments・demoを維持 |
| 手順と保全 | Windowsでtest_frontend.ps1の通し実行・終了コード0。誤ったDB_HOSTで既存テストは期待どおり接続前拒否。秘密情報制御の隔離検証も成功 |

実ブラウザー検証はheadless Chromiumです。モックでの通信失敗・遅い応答・429時計制御を、実ネットワーク障害の再現済みとは扱いません。全ブラウザー・スクリーンリーダー・WCAG全項目、登録/詳細画面、実HTTPS / AWS / 公開proxyは未検証です。CI workflowは追加済み、既存PHP/Gitleaks workflowは変更していません。GitHub実行URL・対象SHA・結果、必須チェック設定は未確認です。commit/pushは行っていません。

今回見つかった既知原因の失敗は、非rootの/app出力先権限不足（所有権で修正）と、extends先のDB起動依存が実行時に解決されなかったこと（e2e-apiにdepends_onを明記して修正）です。テストや認証制御は無効化していません。

## 過去のHTTP疎通失敗が再発した場合

[前工程](development.md)の「IT読み取り段階で1回失敗、原因未特定」は未解明のままです。今回成功したことを根拠に解消済みとは断定しません。再発時は操作時刻・アカウントの役割・パスとHTTP statusだけを記録し、資格情報/本文/Cookie/CSRFを共有しません。

```powershell
$ErrorActionPreference = 'Stop'
docker compose ps
if ($LASTEXITCODE -ne 0) { throw '起動状態を取得できません。' }
docker compose logs --tail 40 frontend http
if ($LASTEXITCODE -ne 0) { throw 'ログを取得できません。' }
```

frontend healthy、http稼働、dev-db healthy、127.0.0.1:5173/8000のpublishを確認します。ブラウザーNetworkで401（未認証/失効）、419（CSRF）、429（Retry-After）、503（DB/lock利用不可）、接続拒否を区別し、応答statusと安全なerror codeだけを控えます。full compose config、全env、Cookie付きcurl verbose、認証要求のHAR/traceは共有しません。結果不明のlogin/logoutを連打せず、画面の認証状態確認を使います。

Docker Engine 27.5.1のlocalhost公開に関する同一L2の既知制約も[前工程の注意](demo.md)を維持します。ローカルbind確認を、外部端末からの完全な隔離保証とは扱いません。
