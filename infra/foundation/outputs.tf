output "repository_urls" {
  description = "Non-secret URLs. Deployments must append @sha256:... (not mutable tags)."
  value       = { for name, repo in aws_ecr_repository.runtime : name => repo.repository_url }
}
