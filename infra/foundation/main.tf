provider "aws" {
  region              = "ap-northeast-1"
  allowed_account_ids = [var.expected_account_id]
  default_tags {
    tags = { ManagedBy = "Terraform", StateRoot = "foundation", Purpose = "portfolio-demo" }
  }
}

resource "aws_ecr_repository" "runtime" {
  for_each             = toset(["php", "nginx"])
  name                 = "${var.repository_prefix}/${each.key}"
  image_tag_mutability = "IMMUTABLE"
  force_delete         = false
  encryption_configuration {
    encryption_type = "AES256"
  }
  image_scanning_configuration {
    scan_on_push = true
  }
  lifecycle {
    prevent_destroy = true
  }
  # Intentionally no expiration policy: retain deployed and rollback digests.
  # No public/cross-account repository policy or registry-wide configuration.
}
