# React基盤・ログイン・依頼一覧・登録・詳細・担当/状態変更

## 今回の採用範囲（2026-09-30）

SCR-01～03、SCR-04閲覧部分に続き、IT担当者の担当変更・状態変更を追加しました。今回の追加はFR-06・FR-07とAC-10・11・14、横断的なAC-07～09・18・19の画面範囲です。コメント一覧/投稿の画面、AWS・Terraformは未実装です。[画面案](screens.md)、[API契約](api.md)、[認証](authentication.md)、[既存demo](demo.md)を参照してください。

今回の開始HEADは`0233ca9683b2d9d4a839b84cc4448fb53a5555ad`、未コミット差分なし。採用版・lock・既存API・認証・並行処理・migrationは変更しません。以下の登録/詳細工程の履歴と、今回の担当/状態の検証を区別します。

登録/詳細工程の開始時HEADは`f5cfab5d26e8645f6cfa71b6ff71b95edc88503d`で、差分はありませんでした。開発DBは4利用者・4依頼・4コメント・投入記録1件です。この工程の自動登録は隔離環境だけで行い、開発DBのmigration・seed・初期化・volume削除は実行しません。前工程（開始時HEAD `f98fef9957f9bd07ab7c3ad5fa3614424f485077`）の実デモ読み取り検証と、今回の隔離検証を区別します。

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
- 401 / 419、logout開始で本人・一覧・詳細・下書き・エラーを消去。タブ復帰・ブラウザーの戻る/進む・BFCache復帰はmeを再確認します。期限の最終判定はサーバーであり、画面はAPIの失効応答を受けて処理します。登録画面だけはタブを切り替えたことによる下書き消失を避け、同じタブのメモリーに保持し、復帰時は隠してmeを確認後、同一IDかつ社員の場合だけ復元します。失効・別アカウント・確認失敗では復元しません。
- ログイン401は共通資格情報エラー、422は既知の項目エラー、429はRetry-Afterの残秒と送信不可、419は再入力。network/5xxは結果不明としてme確認へ進み、POSTを再送しません。logout失敗も保護表示を消しますが「ログアウトしました」と表示しません。meでログイン中と確認した後に利用者が明示再操作します。
- `POST /login`・logoutの同時操作はメモリー上のin-flight guardで拒否。複数画面の認可をこのguardで代替しません。サーバーのCSRF・試行制限・scope・session transactionは変更していません。開発proxy経由ではLaravelのREMOTE_ADDRはproxyとなり、同じ開発proxyのIP枠を共有します。任意X-Forwarded-Forを信頼する変更は行いません。
- 一覧はサーバーの順序のまま20件、日時/ID降順。ID・タイトル・種別・依頼者・担当者・状態・JST日時を表示し、未担当は設計どおり「未割当」。社員/ITは同一画面で見出しだけを変え、取得範囲・件数はサーバー認可に従います。
- 読込・0件・範囲外ページ・失敗を分け、失敗時に古い行を表示しません。422等の上流本文を無条件に描画せず、安全な固定文言へ変換します。タイトル等はReactのテキストで表示し、innerHTMLを使いません。
- visible label、aria-invalid/describedby、エラー要約へのfocus、見出しへのfocus、native button、skip link、JST表記、日本語の状態文字列を使用。390pxでは行をラベル付き縦配置にします。公開終了日時はローカル専用で未設定なので、日時を捏造せず「外部公開していません」と表示します。

一般登録、remember me、検索/フィルター、UIからのseed・停止機能は追加していません。公開HTTPSのSecure Cookieは既存のproduction強制を維持し、今回のローカルHTTP設定と区別します。

## 登録・詳細を追加した設計判断

- ルートを一覧・new・数字IDの詳細に分け、newを先に判定。社員一覧の登録リンク/0件導線、タイトルの詳細リンクはnative anchorで、通常クリックをSPA遷移にします。直接URL・再読込もme→対象APIで認可。ITのnewは権限不足でフォームを出さず、サーバーのPOST 403も維持します。
- API-06にはtitle/category/bodyだけを送信。`request-input.ts`は既存PHP RequestTextに合わせ、CRLF/CR→LF、Unicode空白の前後除去、タイトル改行の除去前拒否、NUL/不正サロゲート拒否、コードポイント1～100/5000を補助検証します。HTMLのmaxlengthはUTF-16単位で絵文字の上限を変えるため使用しません。DB/APIが最終検証者です。
- 201 JSONの返却IDだけで詳細へ置換遷移し、下書きを消去してAPI-07を取得。詳細取得の失敗はGETの再読込で対処し、登録を再送しません。422では下書きと項目別エラーを表示、403はフォームを閉じます。認証確認・登録・詳細取得は既存世代ガードを共有し、古い依頼/利用者への遅い応答を破棄します。
- 二重クリック/Enterは同期的なin-flight guardで止めます。15秒timeout、network/予期しない応答は結果不明として同じフォームの編集・送信を停止し、一覧を確認するよう案内。同じ文面だけで登録成功と判定しません。冪等キーはAPIへ追加せず、自動再送もしません。明示的に一覧へ戻って新しいフォームを開く場合も、先の結果を確認してから操作します。
- 未送信下書きのリンク移動・戻る/進む・logoutでは破棄確認を行い、取消なら現在のURL・下書きを保持します。戻るの取消時は現在URLを履歴へ戻すため、ブラウザーの進む履歴が置換される制約があります。再読込・タブ終了はbeforeunloadのブラウザー標準確認（表示可否はブラウザー依存）。401/419や別アカウント確認による安全上の消去では確認を挟みません。入力はURL/永続ストレージ/ログへ保存しません。
- 詳細はplain textとpre-wrapで改行を保持し、未担当・JST・日本語状態、completed参照専用を表示。不在/閲覧不可を同じ404案内にし、取得失敗は古い本文を残しません。担当/状態は次節の追加範囲、コメントは引き続き次工程です。

| 要件 | 画面・API | 検証 |
| --- | --- | --- |
| FR-03 / AC-04・05・09・18 | SCR-03 → API-04/06 → service_requests | 入力境界・422・二重送信はモック。社員登録201とIT拒否403・初期値は隔離実API/ブラウザー |
| FR-04 / AC-06・07・08 | SCR-02 → API-05、登録/詳細リンク | A登録の返却IDで一覧反映・Bの所有範囲・IT全件を実ブラウザー |
| FR-05の本文 / AC-07・08・13 | SCR-04 → API-04/07 → users/service_requests | A/B/IT認可・completed・404・直接URL/更新・HTML風本文非実行を実ブラウザー |
| FR-01/02の横断・AC-18/19 | 既存Cookie/CSRF・no-store・認証世代 | 401/419・前アカウント応答・失効時消去はモック、通常Cookie接続は実API。AWSは未検証 |

## 担当・状態変更の追加（2026-09-30）

ITかつ未完了の詳細でだけ[WorkflowControls](../frontend/src/WorkflowControls.tsx)を表示。API-07の認可成功後にAPI-08を取得し、候補は20件・ID順、radioで選びます。ページを変えても選択IDと表示名をメモリーで保持します。現在の担当者が候補ページに見つからないだけでは、停止済みとは判定しません。社員には候補取得も操作欄も提供せず、APIのPolicyが最終的に拒否します。

[Session](../frontend/src/session.ts)の認証世代に加え候補読取の世代/AbortControllerを持ち、ページ移動・別依頼・logout・失効後の古い候補応答を破棄します。担当/状態は既存のmutation guardを共有し、Enter・二重クリック・相互送信・送信中の再読込を止めます。選択/変更可能かはstore側でも確認。PATCHにも既存Cookie・XSRF header・15秒のクライアントtimeoutを使用します。このtimeoutはサーバーのrollback保証ではありません。

| 操作/結果 | 実装した動作・理由 |
| --- | --- |
| 担当変更・解除 | 選択ID（解除はnull）と詳細versionだけをAPI-09へ。解除はopenかつ現在の担当あり。有効なITは担当外も変更可。候補は取得後に停止し得るため有効性の最終判定はAPI |
| 状態変更 | open→in_progress、in_progress→waiting_confirmation、waiting_confirmation→in_progress/completedだけ表示。未担当では開始不可と理由表示。有効性を画面で推測しない |
| 完了 | 「完了後は担当・状態を変更できず再開できない」checkboxと「完了にする」で明示。成功後は候補GET/操作欄を停止。社員承認・再開・編集・削除は追加しない |
| 200成功 | 返却詳細/versionへ置換し、選択・完了確認を消去。クライアントでversionを加算しない。未完了なら候補を取り直す。成功通知にfocus/role=status |
| 409 | allowlistで認識したerror.codeを固定文言へ。古い版・完了済み・同一値・禁止遷移・担当者条件を区別。最新詳細と未完了の候補を再取得し選択解除。旧操作へ新versionを付けて再送しない |
| 422 | 停止した候補等の拒否を案内し、最新詳細/候補を再取得して選び直す。上流の任意メッセージは描画しない |
| 503/network/timeout | 結果不明と案内、再送せず全更新を無効化。「最新情報を確認」でGET成功後に手動で選び直す。失敗したGETは古い版の使用を許可しない |
| 候補GET失敗 | 選択を消して担当/状態を両方停止。候補と詳細の確認を一つの操作でやり直せるようにする。候補GETの409は詳細を1回だけ再取得し、GETの相互再試行ループを作らない。まだ未完了なら手動再確認が必要 |
| 401/419・403/404 | 401/419は既存の認証境界で詳細・候補・選択・エラーを全消去。403/404は詳細/操作を閉じ、固定の権限不足/同一404を表示 |

既存画面案の「409は別の操作で更新されたと通知」は、no_change等まで他人の更新と誤認させるため今回のcode別案内に修正しました。DB/HTTPの契約は変更していません。

FR-06 / AC-10はSCR-04→API-08/09→users/service_requests、FR-07 / AC-11はAPI-10、AC-14はAPI-09/10のexpected_versionに対応します。画面の制御でサーバー認可・行lock・外側transactionを代替しません。コメントとの画面内共通ガードは次工程で追加します。

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

今回追加した画面：社員で一覧の「依頼を登録」（0件なら「最初の依頼を登録」も表示）→タイトル・種別・内容→「登録する」→返却IDの詳細へ移動します。詳細の「一覧へ戻る」と一覧のタイトルリンクを使えます。ITは登録リンクなし、`/requests/new`の直接アクセスも権限不足です。`/requests/{ID}`は本人依頼/IT全件だけ閲覧でき、再読み込み可。完了も閲覧専用です。422なら修正し、結果不明なら再送せず一覧と作成者へ確認してください。手動登録は開発データとして保存されます。**自動登録・A/B拒否・HTML風本文などの検証は次の隔離scriptで行い、開発DBにテスト依頼を追加しないでください。**

IT X/Yで未完了の詳細を開くと「担当・状態の変更」が表示されます。候補を選んで「担当を変更」、openなら「担当を解除」。変更先の状態を選んで「状態を変更」、完了時は確認checkboxを選んで「完了にする」を押します。409の案内後は最新表示を確認して再選択、結果不明なら「最新情報を確認」を使います。**自動更新の確認は必ず隔離scriptで行います。通常画面からの手動変更は開発DBに保存されます。**

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

型検査・lint・88件のモックテスト・build・auditのどれかが失敗したら停止します。次に別Composeプロジェクトのtmpfs PostgreSQLへ既存ガード→random schema→migration→テスト用seedと追加19依頼・候補19人（既存ITと合計21人）を用意し、通常public/index.php・Vite・実Chromiumを接続します。登録・担当/状態変更はその隔離環境だけで実行。開発DBへ追加データを入れません。最後にそのテストプロジェクトだけdownし、`-v`は使いません。実行時資格情報は専用tmpfs volume内の0600ファイルに置き、driver終了時に削除、trace/video/screenshotやHTML reportへ出力しません。追加候補のランダムパスワードは保存・配布せず、ハッシュだけを隔離DBに保持。固定配布パスワードはありません。

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
| Vitest + jsdom + モックAPI | 88件成功。既存58件を維持、担当/状態29件とPATCH契約1件を追加。20/1候補・選択保持、遷移/完了確認、共通guard、返却version、409各code/422/503、失効、再取得失敗、古い候補/更新応答、label/focusを検証 |
| 実Chromium + 隔離実Laravel/PG | 社員Aの登録201→返却IDの詳細→一覧反映、Bの404、IT X/Y閲覧とnew画面拒否・直接POST403、completed参照、不存在と同じ404、登録/詳細の直接URL・再読込、破棄確認取消。既存ログイン/一覧/ログアウト・ページング・タイトルXSS検査を維持。HTML/script風本文の文字表示・改行保持と非実行、390px幅で登録/詳細も横はみ出しなし |
| 担当/状態の隔離実ブラウザー | 20/1候補と選択保持、担当割り当て/変更/open解除、担当外IT、4許可遷移、完了確認・完了後候補GET/操作なし、社員の候補GET/PATCH403、390px幅を検証 |
| 独立X/Yコンテキストの古い版 | 同一versionを開き、Xのcommit後にYが古い画面からPATCH→409 stale_version。最新詳細/候補へ更新し選択解除、PATCHを勝手に再送しない。その後Yが明示再選択/送信した要求だけが更新後versionで200になることも確認。これは順番に操作した古い画面の検証で、同時送信の再現ではない。バックエンドの実並行処理試験はPHP全回帰で維持 |
| 実Chromium + 既存ローカルdemo | 前工程で4人の読み取り・ログアウトを確認済み。今回の登録検証先には使用していない。開発DBの業務3表・投入記録は値を表示せず前後比較して一致、4/4/4/1件を保持 |
| バックエンド回帰 | **270テスト・3,855アサーション・終了コード0**。既存users・認証・依頼・workflow・comments・demoを維持 |
| 手順と保全 | Windowsでtest_frontend.ps1の通し実行・終了コード0。DB_HOST=dev-dbを与えた専用テストは接続前拒否（意図した終了コード1）。Gitleaks files/staged・リンク・diff形式も確認。秘密情報制御自体は変更せず、前工程の隔離検証を維持 |

実ブラウザー検証はheadless Chromiumです。モックでの通信失敗・遅い応答・429時計制御を、実ネットワーク障害の再現済みとは扱いません。全ブラウザー・スクリーンリーダー・WCAG全項目、コメント画面、実HTTPS / AWS / 公開proxyは未検証です。既存frontend workflowが追加テストを自動収集し、隔離E2E runnerも実行します（step名を実装範囲に更新）。PHP/Gitleaks workflowは変更していません。GitHub実行URL・対象SHA・結果、必須チェック設定は未確認です。commit/pushは行っていません。

今回見つかった既知原因の失敗は、非rootの/app出力先権限不足（所有権で修正）と、extends先のDB起動依存が実行時に解決されなかったこと（e2e-apiにdepends_onを明記して修正）です。テストや認証制御は無効化していません。

上記の権限・depends_onは前工程の記録です。登録/詳細工程ではUnicode空白を明示した正規表現がlintのcontrol-regex規則に抵触したため、同じ意味の名前付きエスケープとNULのincludes検査へ修正しました。検査ルールの無効化はしていません。エラー修正入力中に見出しへfocusを奪わないよう、見出しfocusは画面/ページの変更時に限定しました。

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
