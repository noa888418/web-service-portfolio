# Dedicated key for state and lock objects. It must outlive all retained state versions.
resource "aws_kms_key" "state" {
  description             = "Terraform state and lock encryption"
  enable_key_rotation     = true
  deletion_window_in_days = 30
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid       = "EnableAccountIAMDelegation"
      Effect    = "Allow"
      Principal = { AWS = "arn:aws:iam::${var.expected_account_id}:root" }
      Action    = "kms:*"
      Resource  = "*"
    }]
  })
  lifecycle {
    prevent_destroy = true
  }
}
