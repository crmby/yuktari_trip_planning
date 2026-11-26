[CmdletBinding()]
param([switch]$DryRun)
$ErrorActionPreference = 'Stop'
Push-Location -LiteralPath $PSScriptRoot
try {
    $scriptPath = Join-Path $PSScriptRoot 'scripts/deploy.mjs'
    if ($DryRun) { & node $scriptPath --dry-run } else { & node $scriptPath }
    $deployExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $deployExitCode
