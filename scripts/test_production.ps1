param([switch]$Prepare)
$ErrorActionPreference = 'Stop'
function Invoke-Production {
    & python scripts/production.py @args
    if ($LASTEXITCODE -ne 0) { throw 'Production command failed. Stop; see the non-sensitive results.' }
}
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    Invoke-Production build
    try {
        if ($Prepare) {
            Invoke-Production setup
            Invoke-Production prepare
        }
        Invoke-Production test
        Invoke-Production scan
    } finally {
        if ((Test-Path '.local/production/runtime.env') -and (Test-Path '.local/production/database.env')) {
            Invoke-Production stop
        }
    }
} finally { Pop-Location }
