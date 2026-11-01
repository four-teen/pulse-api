$ErrorActionPreference = 'Stop'

$pulseApiRoot = Split-Path -Parent $PSScriptRoot
$workspaceRoot = Split-Path -Parent $pulseApiRoot
$synkRoot = Join-Path $workspaceRoot 'synk'
$synkDatabaseConfig = Join-Path $synkRoot 'backend\db_config.local.php'
$localEnvironmentFile = Join-Path $pulseApiRoot '.env.local'

if (-not (Test-Path -LiteralPath $synkDatabaseConfig -PathType Leaf)) {
    throw 'Synk local database config is missing. Configure backend/db_config.local.php first.'
}
if (-not (Test-Path -LiteralPath $localEnvironmentFile -PathType Leaf)) {
    throw 'Copy .env.local.example to .env.local and set a random local test token first.'
}

$tokenLine = Get-Content -LiteralPath $localEnvironmentFile | Where-Object { $_ -match '^\s*SINQ_FACULTY_LOCAL_TEST_TOKEN\s*=' } | Select-Object -First 1
if (-not $tokenLine) { throw 'SINQ_FACULTY_LOCAL_TEST_TOKEN is missing from .env.local.' }
$localToken = ($tokenLine -split '=', 2)[1].Trim().Trim('"').Trim("'")
if ($localToken.Length -lt 32 -or $localToken -eq 'replace-with-a-private-random-local-token') {
    throw 'Set a random local test token of at least 32 characters in .env.local.'
}

$previousToken = $env:SYNK_PULSE_API_TOKEN
$env:SYNK_PULSE_API_TOKEN = $localToken
Push-Location -LiteralPath $synkRoot
try {
    Write-Output 'Serving local Synk on http://127.0.0.1:8081. Press Ctrl+C to stop it.'
    php -S 127.0.0.1:8081 -t $synkRoot (Join-Path $PSScriptRoot 'synk-export-router.php')
} finally {
    Pop-Location
    if ($null -eq $previousToken) { Remove-Item Env:SYNK_PULSE_API_TOKEN -ErrorAction SilentlyContinue }
    else { $env:SYNK_PULSE_API_TOKEN = $previousToken }
}
