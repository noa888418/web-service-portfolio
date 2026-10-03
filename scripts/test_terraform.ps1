$ErrorActionPreference = 'Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    & python scripts/terraform_checks.py check
    if ($LASTEXITCODE -ne 0) { throw 'Terraform checks failed. Stop and inspect the results.' }
    & python scripts/secrets.py files
    if ($LASTEXITCODE -ne 0) { throw 'Secret scan failed.' }
    & git diff --check
    if ($LASTEXITCODE -ne 0) { throw 'Diff whitespace check failed.' }
    & git diff --cached --check
    if ($LASTEXITCODE -ne 0) { throw 'Staged diff whitespace check failed.' }
} finally { Pop-Location }
