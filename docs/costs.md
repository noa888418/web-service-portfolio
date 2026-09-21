# AWS 費用見積もり案

## 結論と見積もりの位置付け

**月額予算 3,000 円はユーザー決定です。** [基本設計案](architecture.md)を下記の規模・時間で運用し、期間外に ALB・ECS・RDS を撤去する場合、月 **12.1244 USD ≒ 2,134 円（税込）** を見込みます。1 USD = 160 円の仮定で、予備費は **約 866 円（予算の約 29%）** です。料金上限や実測値ではなく、使用量に依存する見積もりです。

公式料金・課金条件の確認日：**2026-09-21**。東京リージョン（ap-northeast-1）、CloudFront は日本のエッジ料金、On-Demand / 従量課金を参照しました。無料枠、CloudFront の無料配信量・無料リクエスト、RDS の無料 backup 割当、クレジット、Savings Plans、Spot の割引を差し引いていません。標準で追加料金のない通信・機能は無料枠と区別します。

## 計算条件（提案）

- ユーザーの公開条件は「月 60 時間程度、事前案内期間のみ」。この見積もりは依頼に沿って **構築・検証・公開・撤去完了まで合計 60 時間** とします。例は準備・検証・撤去 12 時間、利用者向け公開 48 時間。公開そのものを 60 時間確保したい場合は準備時間を加算して再計算します。
- ALB 1 台、RDS PostgreSQL db.t4g.micro Single-AZ 1 台、gp3 20 GiB を各 60 時間。標準サポート期間内の PostgreSQL を選ぶ前提で Extended Support 料金は含めません。RDS の実データ量ではなく割当 storage を課金します。
- ECS Fargate Linux x86_64、**1 task 合計 0.5 vCPU / 1 GiB** に Nginx と Laravel の 2 container。60 task-hour に rolling deploy の重複・migration・seed・復元検証等の追加 4 task-hour を加え、計 64 task-hour。性能検証で不足すればサイズと費用を見直します。
- public IPv4 は通常タスク 1 個につき 1 個、計 64 address-hour。内部 ALB・非公開 RDS・CloudFront VPC origin の private ENI に public IPv4 料金を足しません。Elastic IP の固定保持はしません。
- ALB は平均 1 LCU を 60 時間と仮定。実際は接続数・有効接続・処理 byte・rule 評価の最大次元で変動し、1 LCU が固定最低料金という意味ではありません。
- CloudFront 配信 10 GB / 月、HTTPS 100,000 requests / 月（更新要求を含む）、origin への request body 等 0.1 GB。公開案内だけで不特定のアクセスを排除できるとは仮定しません。
- snapshot / backup 合計 20 GB-month、ECR 保存 2 GB-month（2 image・旧版・SBOM 等）、Secrets Manager 4 secret を 1 か月保持、API 1,000 回。snapshot の複数世代合計と一時重複も実績で確認します。
- CloudWatch Logs 取込 1 GB、平均保存 0.1 GB-month（圧縮後の仮定）、標準メトリクス alarm 2 個。S3 は state の過去 version・アクセスログ等を合計 1 GB-month、PUT / LIST 1,000 回、GET 10,000 回とします。ログ増加は予備費の対象です。
- 月間 storage 按分は平均 730 時間で概算。実請求は月の日数・サービスの秒 / 時間の課金単位によります。Fargate の標準 20 GiB ephemeral storage 内で運用し、追加領域は使いません。
- 為替 **160 円/USD**、消費税 **10%** を仮定します。為替は取得した実勢レートではありません。請求時の換算・決済手数料は未確定です。AWS の掲載料金は税抜であり、実際の課税は請求先条件に従います。[AWS 日本の税案内](https://aws.amazon.com/tax-help/japan/)

## 単価の根拠

AWS の料金ページは地域選択が動的なため、東京の数値は AWS 公開 Price List JSON の OnDemand / USD でも照合しました。`current` URL は将来更新されるため、確認日・usage type / SKU を併記しています。これは AWS アカウントの実請求へのアクセスではありません。

| 記号 | 公式資料・確認した料金データ | 採用単価・識別条件 |
| --- | --- | --- |
| P1 | [Fargate 料金](https://aws.amazon.com/fargate/pricing/)、[東京 AmazonECS JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AmazonECS/current/ap-northeast-1/index.json)（公開 2026-09-11） | x86 vCPU $0.05056/h（SKU KBQ3Q6DY9J327G8N）、memory $0.00553/GB-h（JQEE6EF5FAF2AESH）。イメージ取得開始から課金、Linux 最小 1 分 |
| P2 | [ALB 料金](https://aws.amazon.com/elasticloadbalancing/pricing/)、[東京 AWSELB JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AWSELB/current/ap-northeast-1/index.json)（公開 2026-09-11） | Application / AWS Region：$0.0243/h（98ZU8QNDMR4AS8FJ）、$0.008/LCU-h（F2UQT6CZGM7BTWS8） |
| P3 | [RDS PostgreSQL 料金](https://aws.amazon.com/rds/postgresql/pricing/)、[東京 AmazonRDS JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AmazonRDS/current/ap-northeast-1/index.json)（公開 2026-09-17） | db.t4g.micro Single-AZ $0.025/h（YXENKSJFV9BXQF4N）、gp3 $0.138/GB-month（YM9AR73XYRPUSCN3）、backup $0.095/GB-month（8EP35HPTSYF38J3V） |
| P4 | [VPC 料金](https://aws.amazon.com/vpc/pricing/)、[東京 AmazonVPC JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AmazonVPC/current/ap-northeast-1/index.json)（公開 2026-09-17） | InUse / Idle public IPv4 とも $0.005/address-h。InUse SKU ZP85FQT9FHKJRAG5 |
| P5 | [CloudFront 従量課金](https://aws.amazon.com/cloudfront/pricing/pay-as-you-go/)、[AmazonCloudFront JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AmazonCloudFront/current/index.json)（公開 2026-09-16） | 日本の最初の従量帯：配信 $0.114/GB、HTTPS $0.012/10,000 requests、origin 向け $0.060/GB。JP-DataTransfer-Out-Bytes、JP-Requests-Tier2-HTTPS / JP-Requests-HTTPS-Proxy 等 |
| P6 | [ECR 料金](https://aws.amazon.com/ecr/pricing/) | Private image storage $0.10/GB-month。同一リージョンの ECR → Fargate 転送は標準で追加料金なし |
| P7 | [Secrets Manager 料金](https://aws.amazon.com/secrets-manager/pricing/)、[東京 AWSSecretsManager JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AWSSecretsManager/current/ap-northeast-1/index.json)（公開 2026-09-11） | $0.40/secret-month、API $0.05/10,000 回 |
| P8 | [CloudWatch 料金](https://aws.amazon.com/cloudwatch/pricing/)、[東京 AmazonCloudWatch JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AmazonCloudWatch/current/ap-northeast-1/index.json)（公開 2026-09-18） | Standard logs 取込 $0.76/GB、保存 $0.033/GB-month、標準 alarm $0.10/metric-month（FBMB6TVC844YGZ3J） |
| P9 | [S3 料金](https://aws.amazon.com/s3/pricing/)、[東京 AmazonS3 JSON](https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/AmazonS3/current/ap-northeast-1/index.json)（公開 2026-09-18） | Standard $0.025/GB-month、PUT / LIST $0.0047/1,000 回、GET $0.0037/10,000 回 |

CloudFront は定額プランではなく従量課金を前提にします。定額プランに含まれる WAF / Route 53 等を、この見積もりで利用できるとみなしません。VPC origin を含む AWS origin → CloudFront の取得転送は標準料金上追加なし、CloudFront → viewer は上表どおり全量を計上します。[CloudFront の料金区分](https://aws.amazon.com/cloudfront/pricing/pay-as-you-go/)

AWS 管理 KMS key は作成・保存の月額料金がありませんが、API 呼出料金は別です。無料リクエスト枠を前提にせず、下表の小口費用枠に含め、実装時に通信経路・通知方式と合わせて積算し直します。[KMS の料金条件](https://aws.amazon.com/kms/pricing/)

## 月 60 時間の内訳

| 項目 | 計算式（USD） | 月額 USD |
| --- | --- | ---: |
| Fargate（P1） | 64 × (0.5 × 0.05056 + 1 × 0.00553) | 1.97184 |
| public IPv4（P4） | 64 × 0.005 | 0.32000 |
| ALB 時間（P2） | 60 × 0.0243 | 1.45800 |
| ALB LCU（P2） | 60 × 1 × 0.008 | 0.48000 |
| RDS instance（P3） | 60 × 0.025 | 1.50000 |
| RDS gp3（P3） | 20 × 0.138 × 60 / 730 | 0.22685 |
| 保存 snapshot / backup（P3） | 20 × 0.095、無料割当控除なし | 1.90000 |
| CloudFront 配信（P5） | 10 × 0.114 | 1.14000 |
| CloudFront HTTPS（P5） | 100,000 / 10,000 × 0.012 | 0.12000 |
| CloudFront → origin（P5） | 0.1 × 0.060 | 0.00600 |
| ECR 保存（P6） | 2 × 0.10 | 0.20000 |
| Secrets Manager 保存（P7） | 4 × 0.40 | 1.60000 |
| Secrets Manager API（P7） | 1,000 / 10,000 × 0.05 | 0.00500 |
| CloudWatch Logs 取込・保存（P8） | 1 × 0.76 + 0.1 × 0.033 | 0.76330 |
| CloudWatch alarm（P8） | 2 × 0.10、常設 1 か月で保守的に計上 | 0.20000 |
| S3 保存・リクエスト（P9） | 1 × 0.025 + 1,000 / 1,000 × 0.0047 + 10,000 / 10,000 × 0.0037 | 0.03340 |
| その他通信・小口費用の概算枠 | AZ 間 DB 通信・復元時の転送、KMS API・通知等に 0.20 を確保。これは公式単価ではなく未確定項目の予算枠 | 0.20000 |
| **合計** | 丸め前の値で合算 | **12.12439** |

**円換算：12.124389… × 160 × 1.10 = 2,133.89 円 → 約 2,134 円。** 追加余裕は 3,000 − 2,134 = 約 866 円です。その他通信・小口費用枠 $0.20 は月額に計上済みで、予備費とは別ですが、上限を保証しません。DB と task の AZ を固定できない場合や外向き API / イメージのリージョンを変える場合は、経路別の公式単価と使用量で置き換えます。

## 公開期間外の費用と運用比較

| 項目 | 非公開期間の扱い |
| --- | --- |
| ALB・Fargate・RDS compute・public IPv4 | runtime の削除完了後は該当リソースの時間課金が終わる。伝播・削除待ちや残存 task も 60 時間に含める |
| DB snapshot / backup | DB を削除しても残り、上表では月 $1.90 を計上。鍵も必要。保存世代・容量が増えると増額 |
| ECR・secret・S3・ログ保存・alarm | 期間外も課金。上表は常設分を含む。AWS 管理 key を使う前提で customer managed KMS key の月額は含めない |
| CloudFront・VPC・subnet・SG・IGW・IAM | 常設案。リクエストや転送がなければ、この構成のための固定時間料金は見込まない。閉鎖反映前の応答や閉鎖用ページの提供を追加すれば別途課金を考慮 |

RDS の停止だけで月全体を安く維持する案は採りません。停止中も storage / backup は課金され、連続 7 日停止後に自動起動します。[RDS 停止の仕様](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_StopInstance.html)

比較（160 円/USD、税込）：ALB を 730 時間残すと **時間料金だけで約 3,122 円**、RDS micro を 730 時間稼働すると **compute だけで約 3,212 円**。RDS を停止しても gp3 20 GiB を月全体残せば約 486 円です。ECS の desired count を 0 にするだけでは月 3,000 円の条件を満たせません。削除・snapshot 復元を含む [運用案](architecture.md) が見積もりの前提です。

## 予備費・超過要因

- 為替が 180 円/USD なら同じ利用量でも **約 2,401 円**、予備費は約 599 円になります。決済手数料や請求時の丸めも余裕内で確認します。
- 同規模の ALB・task・IPv4・RDS・gp3 と 1 LCU を 1 時間延長すると約 **17.05 円**（160 円/USD・税込、通信等を除く）。公開 60 時間に別途準備 12 時間を足すなら、約 205 円以上を加算します。
- CloudFront 配信がさらに 100 GB 増えると配信だけで約 **2,006 円**増えます。事前案内だけではアクセス増加を防げず、従量課金に支出の上限はありません。
- task の増設・メモリ増量、再構築失敗や放置、ALB LCU の増加、RDS の CPU credit 超過（T4g PostgreSQL は $0.075/vCPU-hour）、追加 storage / snapshot、ログ大量出力、ECR の旧 image 蓄積を確認します。
- WAF、NAT Gateway、Redis、有料 VPC Endpoint、独自ドメイン / Route 53、有料 support、customer managed KMS key、Extended Support、CloudWatch Logs Insights の検索等は初期案に含めません。追加時は先に再見積もりします。監視を無効にして予算を合わせる方針ではありません。
- GitHub Actions の有料 minutes 等は AWS 予算とは別です。AWS 料金内訳に含めていないため、GitHub 側の利用条件も CI 実装時に確認します。

## 予算通知と停止の責任

AWS Budgets で 3,000 円相当の月額予算を設定し、50%・70%・90% の実績と予測超過を通知する案です。USD / 税抜で管理する場合の上限目安は `3,000 / 160 / 1.10 ≒ $17.05`。税を含む設定との二重計上を避け、為替の想定を月初に見直します。

**予算通知は支出の強制停止ではありません。** 請求反映・通知に遅延があり、通知前後に増額することがあります。通知先、公開終了・撤去の実行者、終了予定時刻を決め、公開前後に稼働リソースと累積時間を確認します。予算通知を受けて state や snapshot を一括削除する運用にはしません。[AWS Budgets の通知と遅延](https://docs.aws.amazon.com/cost-management/latest/userguide/budgets-managing-costs.html)

本見積もりの数式はローカルで再計算していますが、AWS リソースは未作成で実測費用・性能・課金停止は未検証です。最初の短時間検証後に実績単価・量と照合し、予備費を使い切る見込みなら公開時間・構成を再検討します。
