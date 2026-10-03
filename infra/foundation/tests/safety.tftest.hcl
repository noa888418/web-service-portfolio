# Synthetic values used only by the mock provider; not deployment inputs.
mock_provider "aws" {}

variables {
  expected_account_id = "123456789012"
  repository_prefix   = "mock-only-portfolio"
}

run "image_safety" {
  command = plan
  assert {
    condition     = toset(keys(aws_ecr_repository.runtime)) == toset(["php", "nginx"])
    error_message = "Only the two final production images belong here."
  }
  assert {
    condition = alltrue([for r in aws_ecr_repository.runtime :
      r.image_tag_mutability == "IMMUTABLE" && !r.force_delete &&
      r.image_scanning_configuration[0].scan_on_push &&
      r.encryption_configuration[0].encryption_type == "AES256"
    ])
    error_message = "Repositories must be immutable, encrypted, scanned and protected from forced deletion."
  }
}

run "reject_account" {
  command = plan
  variables {
    expected_account_id = "not-an-account"
  }
  expect_failures = [var.expected_account_id]
}

run "reject_prefix" {
  command = plan
  variables {
    repository_prefix = "Not/Valid"
  }
  expect_failures = [var.repository_prefix]
}
