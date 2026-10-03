variable "expected_account_id" {
  description = "Verified deployment account ID, never an access key. No default."
  type        = string
  nullable    = false
  validation {
    condition     = can(regex("^[0-9]{12}$", var.expected_account_id))
    error_message = "Supply the verified 12-digit AWS account ID."
  }
}

variable "repository_prefix" {
  description = "Reviewed lowercase prefix for the php and nginx private repositories."
  type        = string
  nullable    = false
  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{1,28}[a-z0-9]$", var.repository_prefix))
    error_message = "Use 3-30 lowercase letters/digits/hyphens, starting with a letter."
  }
}
