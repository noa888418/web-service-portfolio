variable "expected_account_id" {
  description = "Verified deployment account ID, never an access key. No default."
  type        = string
  nullable    = false
  validation {
    condition     = can(regex("^[0-9]{12}$", var.expected_account_id))
    error_message = "Supply the verified 12-digit AWS account ID."
  }
}

variable "state_bucket_name" {
  description = "Globally unique private state bucket name; confirmed by the operator."
  type        = string
  nullable    = false
  validation {
    condition = (
      can(regex("^[a-z][a-z0-9-]{1,61}[a-z0-9]$", var.state_bucket_name)) &&
      !can(regex("^(xn--|sthree-|amzn-s3-demo-)", var.state_bucket_name)) &&
      !can(regex("(-s3alias|--ol-s3|--x-s3|--table-s3|-an)$", var.state_bucket_name))
    )
    error_message = "Use 3-63 lowercase letters/digits/hyphens, start with a letter, and avoid S3 reserved names."
  }
}
