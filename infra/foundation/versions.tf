terraform {
  required_version = "= 1.16.5"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "= 6.67.0"
    }
  }
  # Bucket, kms_key_id and allowed_account_ids are provided during a future reviewed init.
  backend "s3" {
    region       = "ap-northeast-1"
    key          = "foundation/terraform.tfstate"
    encrypt      = true
    use_lockfile = true
  }
}
