output "state_bucket_name" {
  description = "Non-secret bucket name for reviewed backend configuration."
  value       = aws_s3_bucket.state.id
}

output "state_kms_key_arn" {
  description = "Non-secret ARN required by every S3 backend kms_key_id."
  value       = aws_kms_key.state.arn
}
