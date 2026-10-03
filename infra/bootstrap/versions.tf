terraform {
  required_version = "= 1.16.5"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "= 6.67.0"
    }
  }
  # Initial construction only. Migrate this state to S3 after bucket verification.
  backend "local" {
    path = "../../.local/terraform-state/bootstrap.tfstate"
  }
}
