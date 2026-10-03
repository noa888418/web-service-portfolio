# Synthetic values used only by the mock provider; not deployment inputs.
mock_provider "aws" {
  mock_resource "aws_kms_key" {
    override_during = plan
    defaults = {
      arn = "arn:aws:kms:ap-northeast-1:123456789012:key/00000000-0000-0000-0000-000000000000"
    }
  }
}

variables {
  expected_account_id = "123456789012"
  state_bucket_name   = "mock-only-portfolio-state"
}

run "state_safety" {
  command = plan
  assert {
    condition = (
      !aws_s3_bucket.state.force_destroy &&
      aws_s3_bucket_public_access_block.state.block_public_acls &&
      aws_s3_bucket_public_access_block.state.block_public_policy &&
      aws_s3_bucket_public_access_block.state.ignore_public_acls &&
      aws_s3_bucket_public_access_block.state.restrict_public_buckets
    )
    error_message = "State must reject public access and forced deletion."
  }
  assert {
    condition     = aws_s3_bucket_versioning.state.versioning_configuration[0].status == "Enabled"
    error_message = "State recovery needs versioning."
  }
  assert {
    condition     = one(aws_s3_bucket_server_side_encryption_configuration.state.rule).apply_server_side_encryption_by_default[0].sse_algorithm == "aws:kms"
    error_message = "State needs explicit encryption."
  }
  assert {
    condition     = aws_s3_bucket_ownership_controls.state.rule[0].object_ownership == "BucketOwnerEnforced"
    error_message = "ACLs must be disabled."
  }
  assert {
    condition = (
      jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Effect == "Deny" &&
      jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Condition.Bool["aws:SecureTransport"] == "false" &&
      length(jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Resource) == 2
    )
    error_message = "Bucket and objects must deny non-TLS access."
  }
}

run "state_key_safety" {
  command = plan
  assert {
    condition     = aws_kms_key.state.enable_key_rotation && aws_kms_key.state.deletion_window_in_days == 30
    error_message = "State key needs rotation and a recovery window."
  }
}

run "reject_account" {
  command = plan
  variables {
    expected_account_id = "not-an-account"
  }
  expect_failures = [var.expected_account_id]
}

run "reject_bucket" {
  command = plan
  variables {
    state_bucket_name = "Invalid.Bucket"
  }
  expect_failures = [var.state_bucket_name]
}

run "reject_reserved_bucket" {
  command = plan
  variables {
    state_bucket_name = "mock-only--table-s3"
  }
  expect_failures = [var.state_bucket_name]
}
