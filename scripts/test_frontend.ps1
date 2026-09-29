$ErrorActionPreference = 'Stop'
function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw 'Docker command failed. Stop; see the non-sensitive output above.' }
}
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    Invoke-Docker compose --profile frontend build frontend
    Invoke-Docker compose --profile frontend run --rm --no-deps frontend npm run check
    try {
        Invoke-Docker compose -f compose.e2e.yaml --profile e2e build e2e-api browser
        Invoke-Docker compose -f compose.e2e.yaml --profile e2e up -d --wait frontend
        Invoke-Docker compose -f compose.e2e.yaml --profile e2e run --rm browser
    } finally {
        # This explicit project has only isolated test resources, no dev_data.
        Invoke-Docker compose -f compose.e2e.yaml --profile e2e down
    }
} finally {
    Pop-Location
}
