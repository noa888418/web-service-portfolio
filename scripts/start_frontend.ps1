$ErrorActionPreference = 'Stop'
function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw 'Docker command failed; startup stopped.' }
}
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    # Existing migrated demo DB is required. Never migrate, seed or reset here.
    Invoke-Docker compose --profile frontend build frontend
    Invoke-Docker compose --profile frontend --profile http up -d --wait http frontend
    Write-Host 'Open http://127.0.0.1:5173/login. Use your existing local demo credentials.'
} finally {
    Pop-Location
}
