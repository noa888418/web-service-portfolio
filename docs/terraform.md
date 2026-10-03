# Terraform初期基盤・AWS移行差分

2026-10-03。今回採用した範囲は **bootstrapのstate用S3と保護用KMS鍵、foundationの本番2イメージ用private ECR、およびAWSに接続しない検査** です。VPC・ALB・RDS・ECS・CloudFront・IAM/OIDC・予算通知は未実装。実AWSへのplan/apply/destroy、ECR push、開発DBの変更は行っていません。

## バージョンと構成

| 対象 | 固定版・理由 | 公式資料（2026-10-03確認） |
| --- | --- | --- |
| Terraform CLI | 1.16.5。安定版をexact制約にする。1.17 betaは不採用 | [公式配布](https://releases.hashicorp.com/terraform/1.16.5/)、[mock provider](https://developer.hashicorp.com/terraform/language/tests/mocking) |
| AWS Provider | 6.67.0。従来の調査版6.65.0から更新し、各rootのexact制約とlockで固定 | [リリース](https://github.com/hashicorp/terraform-provider-aws/releases/tag/v6.67.0)、[Provider仕様](https://registry.terraform.io/providers/hashicorp/aws/6.67.0/docs) |
| IaC検査 | Trivy0.74.0。既存image検査と同じdigest、組込Terraformルールを使用 | [公式config検査](https://trivy.dev/docs/latest/scanner/misconfiguration/)、[固定値](../config/terraform.json) |

mockはTerraform1.7以降、S3 lockfileは1.10以降の機能です。選定したProviderとCLIのschema読込み・validate・mock計画で今回のリソース定義の互換性を検証します。mock成功はAWS API・IAM・実際の作成可否の証明ではありません。CLIは公式SHA256と照合して`.tools/terraform/1.16.5`へ導入し、システムのPATHを変更しません。Providerは公式署名を確認し、Windows/Linux amd64両方のchecksumを各rootの`.terraform.lock.hcl`へ記録。更新時はconfig・CLI制約・両lock・検証結果を一緒にレビューします。

| state root | 今回の実装 | state key / 役割 |
| --- | --- | --- |
| [bootstrap](../infra/bootstrap/main.tf) | S3、SSE-KMS、公開禁止、ACL無効化、versioning、TLS強制、専用KMS鍵 | 初回はGit外local。移行後`bootstrap/terraform.tfstate`、保管基盤専用権限 |
| [foundation](../infra/foundation/main.tf) | php/nginxのprivate ECR 2個のみ | `foundation/terraform.tfstate`、将来network等を追加 |
| edge | **未作成、検査対象外** | 将来`edge/terraform.tfstate`、CloudFront/OAC等 |
| runtime | **未作成、検査対象外** | 将来`runtime/terraform.tfstate`、期間限定ALB/ECS/RDS等 |

rootごとに別key・操作権限を用い、workspace切替だけに依存しません。foundationはbootstrapのremote_stateを読まず、確認済みbucket名・KMS ARNをbackend入力で受け取ります。不要なstate全体の読取権限を増やしません。

## 必要な入力と保護対象

実AWSアカウントID、bucket名、repository prefix、予算通知先は未提示で、defaultを捏造していません。

| 入力 | 条件 |
| --- | --- |
| `expected_account_id`（両root） | 確認済み12桁。Providerの`allowed_account_ids`で誤アカウントを拒否 |
| `state_bucket_name` | 3～63文字、英小文字開始、英小文字/数字/ハイフン、末尾英数字、[S3予約名](https://docs.aws.amazon.com/AmazonS3/latest/userguide/bucketnamingrules.html)を除外（--x-s3/--table-s3/-an等を含む）。世界的な一意性は実AWSで要確認 |
| `repository_prefix` | 3～30文字、英小文字開始、英小文字/数字/ハイフン、末尾英数字。生成名は`prefix/php`と`prefix/nginx` |
| backend入力 | 確認済みbucket、`allowed_account_ids`、**state専用`kms_key_id` ARN**。region東京、keyはroot別、encrypt/use_lockfileはtrue。資格情報を記入しない |
| 将来必要 | 作業者/CI role、OIDCのrepo・Environment、予算通知先、CIDR/AZ、公開時間、資格情報配布経路。今回は設定しない |

非機密の実環境入力もGit外の`.local/terraform-inputs/`で扱い、秘密値はtfvars・outputへ入れません。state/backup/plan/export JSON、`.terraform`、backend実値は除外、lockは管理。`sensitive`はstateからの除外ではありません。後工程のSecrets Manager等への値の投入は別の安全な経路を用意します。

S3はBlock Public Access4項目、BucketOwnerEnforced、versioning、HTTP明示Denyを実装。PutObjectにはaws:kmsと専用鍵ARNの明示指定を必須にし、省略/別方式/別鍵をbucket policyで拒否します。backendのkms_key_id未指定を暗黙のAES256へフォールバックさせません。`force_destroy=false`と`prevent_destroy=true`を設定し、旧state世代の自動削除は設けません。排他は**S3 backendの`.tflock`**であり、S3 Object Lockとは別です。初回local backendも通常のlocal lockを使い、同時実行しません。[S3 backend](https://developer.hashicorp.com/terraform/language/backend/s3)

初回IaC検査でSSE-S3案にAWS-0132/HIGHを検出したため、基準を下げず**state専用customer managed KMS key**へ変更しました。key rotation有効・削除待機30日・prevent_destroyを設定し、key policyは自アカウントのIAMへの委任だけです（[AWSの標準委任方式](https://docs.aws.amazon.com/kms/latest/developerguide/key-policy-default.html)）。通常のstate roleにはEncrypt/Decrypt/GenerateDataKey/DescribeKeyの必要範囲のみを許可し、DisableKey/ScheduleKeyDeletion/PutKeyPolicyは付与しない後工程です。古いstateを保持する限り復号鍵も保持します。[費用への反映](costs.md)

`prevent_destroy`はコードにresourceが残るときのTerraform上の保護で、resourceブロック削除やAWS API操作を防ぎません。通常runtime撤去roleにはstate bucket/過去version削除、保護設定変更、KMS無効化/削除、ECR削除を与えず、保管基盤の管理者を分ける必要があります。**IAMは今回未実装**で、実際の権限保護は未検証です。

## ECRの保持・配布・検査

private、IMMUTABLE（除外タグなし）、AES256、scan_on_push=true、force_delete=false、prevent_destroy=trueを実装。公開/cross-account policyとregistry全体設定は追加しません。[タグ不変性](https://docs.aws.amazon.com/AmazonECR/latest/userguide/image-tag-mutability.html)

**初期版では自動expireを設定せず全digestを保持**します。タグなしでもECSがdigest参照するため、日数/件数だけで削除しません。稼働版・最低1組の動作確認済みrollback版（PHP/Nginx）・snapshot復元に必要な版を台帳へ記録する運用案です。作成者が各公開終了時に2GB枠の使用量を確認し、task definition・現稼働task・rollback・snapshotの参照と照合した未使用版だけを別のレビュー済み作業で削除します。通常push/deploy roleには削除権限を与えません。

後続デプロイは一意タグでpushし、ECSには`repository_url@sha256:...`を指定。scan/SBOMと実際にpushしたmanifest/platformの対応を照合します。TrivyのOS/Composer/npm検査とSBOMを維持し、ECR basic（OS中心）は補助検査とします。

basicは標準で追加料金なしです。有料Inspector enhancedは今回有効化しません。既存registryがenhancedならrepository設定だけでbasicへ戻るとはみなさず、構築前に実設定・適用範囲・費用を確認して停止/再見積もりします。[basic料金の公式案内](https://aws.amazon.com/about-aws/whats-new/2024/08/new-version-amazon-ecr-basic-scanning/)、[検査方式](https://docs.aws.amazon.com/AmazonECR/latest/userguide/image-scanning.html)

## AWSに接続しない検査とPowerShell手順

新しいGit外ディレクトリに`.tf`・lock・mockテストだけをコピーします。実backend/state/tfvarsを読み込まず、AWS関連環境変数・TF引数を除外、空AWS設定・metadata無効化で実行。CLI/Provider/Docker Hubの公開配布元には接続しますが、AWSアカウント/APIには接続しません。Trivy containerはnetwork=noneで、固定imageの組込ルールを使います。

1. 元ソースに`terraform fmt -check -recursive infra`。
2. 実装済み2rootだけに`terraform init -backend=false -input=false -lockfile=readonly`、`terraform validate`。
3. `terraform test`のmock_provider awsとcommand=planで保護設定と入力拒否を検査。**実AWS planではない**。
4. Trivy configで全重大度を保存。**HIGH/CRITICALが1件以上、実行エラー、report不整合、評価0件は失敗**。LOW/MEDIUMは残存事項として記録。除外ルールを追加しない。exit-code 0で全reportを取得後、Pythonが基準判定し非0を返す。scanner用の名前/IDは明示した架空値だけ。
5. Git除外の正/負例と追跡済み除外対象の不在、Gitleaks files、両diffの書式を検査。

組込ルールを選ぶためTrivyは外部check cache不在とembedded fallbackをログへ出します。これは選定済みの内蔵ルールによる実評価と区別し、実評価件数・findingの整合・プロセス終了を検査します。ルールも更新した場合はimage digest・検証結果を更新します。

結果は`.local/terraform-checks/run-*/`。IaC reportにはsourceが入り得るため今回CI artifactには公開しません。CIはcontents:readのみ、id-token/資格情報なし、push/PRで実行。既存users/frontend/production/Gitleaks workflowは維持。GitHub成功と必須チェック設定は別途確認します。

前提：Docker Desktop Linux containers、Python3.10以上、Git。

```powershell
$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\noano\web-service-portfolio'
python scripts/terraform_checks.py install
if ($LASTEXITCODE -ne 0) { throw 'Terraform installation failed' }
python -m unittest discover -s scripts/tests -p test_terraform_checks.py
if ($LASTEXITCODE -ne 0) { throw 'Gate tests failed' }
& .\scripts\test_terraform.ps1
if (-not $?) { throw 'Terraform validation failed' }
```

2回目以降installは省略可。取得失敗をbackend=falseの解除やlock無視で回避しません。検査にAWSアカウントID・AWSログイン・デモ資格情報は不要です。

## 将来の初回構築・state移行（今回実行しない）

1. 実アカウント・region・bucket名・権限・費用・通知先をレビューし、短期SSO等を用意。通常Providerのアカウント照合を無効化しない。
2. 初回bootstrapはlocal backend。`.local/terraform-state/`をディスク暗号化と作成者限定ACLで保護し、暗号化された別保管先へbackup。Git除外だけでは暗号化されない。暗号化/ACLを確認できなければ実state作成を開始しない。
3. bootstrapだけ通常init→実planを私有ファイルへ保存/レビュー→承認後apply。S3のversioning・SSE-KMS・公開禁止・HTTP拒否とkey/roleを実機確認。作成直後から保護設定完了までの順序も確認する。
4. 全実行を止め、local stateのbackup・lineage/serial・resource対応を秘密値なしで記録。versions.tfのlocal backendだけを[移行テンプレート](../infra/bootstrap/backend.s3.tf.example)へ置換。backendを2つ並べない。private設定に確認済みbucket/allowed_account_ids/**kms_key_id**を指定し、state/lockが専用鍵で暗号化されることを確認する。
5. 元のrootで`terraform init -migrate-state -backend-config=<private-file>`を実行し移行確認へ応答。`-reconfigure`は移行の代替にしない。state push -forceや強制unlockで通さない。[init仕様](https://developer.hashicorp.com/terraform/cli/commands/init)
6. S3 object/version・lineage/serial・resource対応を照合し、独立端末で読取、ロック競合、解除、復元を試験。成功まで旧localを削除しない。成功後はlocalを以後の書込み元にせず、暗号化backupを管理する。backend変更をレビューしてコードに固定する。
7. foundationは別key/権限でbackend init→planレビュー→承認後apply。S3は各state keyのGetObject/PutObject、対応tflockのGet/Put/Delete、対象prefixのListBucket。通常roleにstate本体のDeleteObject/DeleteObjectVersionは不要。S3とKMSの両方の最小権限を確認する。root間でlockは共通ではないので依存変更は順番も管理する。

復元は全実行を止め、S3の適切な過去versionを私有領域へ取得してレビューします。state復元はAWS資源/DB復元と異なります。保護bucket・鍵・保持imageは通常runtime撤去に含めません。復旧と実権限の拒否試験は未実施です。

## AWS移行差分（設計案・今回未実装）

### 書込volume・UID/GID

Compose tmpfsをFargateのlinuxParameters.tmpfsへ移すことはできません。Linux Fargate1.4以降のtask-scoped ephemeral storage上のbind volumeへ置換する案です。標準20GiBには圧縮/展開imageも含み、RAM専用tmpfsではありません。AWS所有鍵で暗号化され、task停止後に破棄されます。container単体再起動で必ず消えるとはみなさず、初期処理は再実行可能にします。[task parameters](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task_definition_parameters.html)、[ephemeral storage](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/fargate-task-storage.html)

| volume案 | mount先 / 利用container | 初期権限・用途 |
| --- | --- | --- |
| php-storage | /app/storage / PHPのみ | 10001:10001・0700、framework用。session/cache本体はPG |
| php-bootstrap-cache | /app/bootstrap/cache / PHPのみ | 同上、package manifest。秘密値を含むconfig cacheは作らない |
| php-tmp | /tmp / PHPのみ | 同上、一時ファイル |
| nginx-tmp | /tmp / Nginxのみ | 同上、pid・生成設定・request temporary file |

bind volumeの既定はroot:root/0755。現行imageのchownだけで新規volume所有者が一致するとは仮定しません。後工程でmount先をmkdir/chown/chmodしてから**Dockerfile VOLUMEを同じ絶対pathで宣言**し、ECSのimage→volume初期化を利用する案です。公式はVOLUMEとcontainerPath一致時のコピーとDockerfileでの所有者指定を説明しています。[bind mount仕様](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/bind-mounts.html)

USER10001、readOnlyRootFilesystemを維持し、同じUIDでもPHP/Nginx間でvolumeは共有しません。root起動やchmod777は採用しません。初回task・再配置・container再起動で所有者/mode・許可path書込・他path拒否・残存を実測し、成立しなければ公開せず再設計します。今回Dockerfile/Composeは変更していません。

### 通信・healthcheck・起動/停止

awsvpcのNginx→PHPは127.0.0.1:9000（Composeはphp:9000）。task SGはALBから8080だけ許可し、9000/DBは外部公開しません。[awsvpc](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task-networking-awsvpc.html)

両containerをessentialとし、PHPのTCP9000 healthcheck後にNginxをdependsOn HEALTHYで起動。Nginx/ALBのhealthzはFPM pingの非機密応答で、DB業務readinessの保証には使いません。healthcheckはtask definitionに明記。依存関係は停止時逆順なのでNginx受付停止→PHP停止です。HEALTHY依存は起動時だけの条件です。[container dependency](https://docs.aws.amazon.com/AmazonECS/latest/APIReference/API_ContainerDependency.html)

後工程でSIGQUIT等のgraceful stop、ALB deregistration delay、stopTimeout（Fargate上限120秒）、FPM30秒/FastCGI40秒、認証lock/transaction上限を整合させます。目安としてdrain60秒・container stop60秒を試験対象にしますが、依存順だけでin-flight commit完了は保証しません。強制終了時のPG rollback・lock解放・502時の非再送もAWSで確認します。

### HTTPS・Host・利用者IP

1. CloudFront viewerはhttps-only。defaultはCachingDisabled、Cookie/Authorization/Origin/Referer/CSRF/全queryを転送。従来AllViewer案を、viewer headersに加え**CF生成CloudFront-Viewer-AddressとCloudFront-Forwarded-Protoを転送する専用origin request policy（allViewerAndWhitelistCloudFront）案**へ具体化します。assetsだけ静的cache。
2. ALBはVPC origin管理SGから、NginxはALB SGからのみ。ALBでHost preserveを有効にし、確認済みCloudFront標準ドメインの完全一致Hostだけをアプリ経路へ通す。default拒否、ALB healthcheckのIP形式Hostはhealthパスだけ例外。[ALB Host保存](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/edit-load-balancer-attributes.html)
3. AWS用Nginx設定で、許可ALB経路・Host・CF生成proto=httpsが揃う要求だけFastCGI HTTPS=on/REQUEST_SCHEME=https/SERVER_PORT=443へ変換。内部HTTPを表すALBのX-Forwarded-Protoをviewer HTTPS判断に使わない。ローカルTLS設定を弱めない。
4. IPはCF生成Viewer-AddressのIP:portを検証して単一IPへ変換し、PHPのREMOTE_ADDRへ渡す案。IPv4/IPv6・port・重複/欠落/不正値を検査する処理が必要。任意XFFの左端/Forwardedは採用せず、FPMへ未検証headerを流さない。Laravelの全proxy信頼は有効にしない。CFが同名viewer入力を置換することは実装前の公式仕様/実機確認条件とし、不明なら公開を止める。[CloudFront生成header](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/adding-cloudfront-headers.html)

SG/Hostだけで任意headerを信用できるとは扱いません。偽造XFF/XFP/Viewer-Address、直接ALB/task、重複Host、IPv6、別利用者のIP制限・Cookie/no-store/cache分離をAWSで確認します。標準証明書の最低TLS制約と内部HTTPのデモ限定例外は[基本設計](architecture.md)を維持します。

### RDS・DB権限・公開seed・PHP版

現行backend/config/database.phpのsslmode=disableはローカル専用で、**そのままAWSへ配布する条件は未達**です。後工程でAWS用verify-full、公式RDS CA bundle（非秘密）とsslrootcert、実endpointのhostname検証、rds.force_sslを追加。誤CA/誤hostname/平文の拒否とCA更新を確認します。環境変数だけで現在の固定disableが変わるとは書きません。[RDS PostgreSQL TLS](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/PostgreSQL.Concepts.General.SSL.html)

アプリDB roleは必要テーブルDML/sequence/schema USAGEのみ、DDL/CREATEROLE/管理者権限なし。migration roleは専用schemaのDDL所有者で通常taskへ注入しません。IAMとSQL権限は別です。公開seedは管理taskで、用途demo・明示許可・publication_id/seed_version・空DB/成功記録・実DB/username/schema一致に加え、AWS account/region/RDS identity/endpointを許可対象と照合する後工程です。現在のlocalガードを緩めて通しません。公開資格情報の配布/失効、最小権限、RDS対応版は未決定/未検証です。

PHPUnit270試験はPHP8.4.25、配布FPMは8.4.26です。HTTPS通しは8.4.26ですが、**270試験全件を8.4.26で成功した証拠ではありません**。後工程で配布Dockerfileのphp-base（同じdigest/拡張/runtime library）からdev依存だけ加えたtest targetを作り、既存接続先ガードと隔離PG/schemaで全suiteを実行する案です。PHPUnit/Composerを最終配布imageに残さず、8.4.25検査を維持してCIへ8.4.26検査を追加します。今回はアプリ/DB不変更のため実施していません。

## 結果と次の単位

2026-10-03、Windows PowerShell/Python3.13/Docker Linux amd64で実行。作業開始時は未コミット差分なしでした。[非機密の検証記録](terraform-verification.json)に版・対象root・ソースSHA256を保存しました。

| 検証 | 結果 |
| --- | --- |
| CLI取得SHA256・Provider署名/lock | 成功。Windows/Linux amd64のchecksumを管理 |
| fmt -check / init -backend=false / validate | 2rootで成功 |
| Terraform mock tests | bootstrap5件 + foundation3件、計8件成功 |
| 検査失敗の伝播 | 初回SSE-S3でAWS-0132/HIGHを検出し終了1。専用KMSへ修正後に再実行 |
| report判定のPythonテスト | 4件成功。空report・不整合・未知severityの拒否等 |
| Trivy config | HIGH/CRITICAL/MEDIUM/UNKNOWN 0、LOW 3件。停止基準は変更なし |
| Windows一括手順 | test_terraform.ps1終了0。Gitleaks files、Git除外/追跡確認、両diff --check成功 |
| アプリ/本番image | ソース・既存workflowを維持。今回PHP270試験・ブラウザー・image scanを再実行していない |
| GitHub CI・実AWS | 未実行。workflow追加は必須チェック設定ではない |

残存LOWは除外・非表示にしていません。

- bootstrap AWS-0089（1件）：state bucketのS3アクセスログ未設定。今回は保管基盤とECRのみで独立監査保存先を未実装。アクセス追跡の不足が残るため、実AWS初回構築前に監査ログ保存先・権限・保存期限・費用をレビューし、必要な実装を追加します。versioningはアクセス監査の代替ではありません。
- foundation AWS-0033（2件）：ECRはAES256で暗号化済みですがcustomer managed keyではないため、鍵単位のアクセス/削除管理を持ちません。秘密値を含まない配布imageとIAM制限を前提に初期案のAES256を維持。鍵分離要件・実在データ・規模変更時はKMS化と費用を再評価します。

未実施：実AWS plan/apply/destroy、S3/KMS実ロック・復元・権限拒否、ECR push/scan、Fargate volume/停止、CF/ALB、RDS TLS/権限、公開seed、PHP8.4.26全270試験、GitHub新workflow成功/必須チェック。

次は配布PHP版の隔離回帰targetとFargate書込領域のimage側準備・ローカル検証を小さな単位にします。その後foundationのnetwork/権限、runtime/edgeを段階的にレビューし、実入力・通知先・承認を揃えてから初回AWS planへ進みます。
