param(
    [int]$Port = 8010,
    [int]$Workers = 4,
    [int]$TaskWorkers = 2,
    [int]$MaxRequests = 500
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Test-PortListening {
    param([int]$PortToCheck)

    $lines = netstat -ano | Select-String ":$PortToCheck"
    foreach ($line in $lines) {
        if (($line.ToString() -replace "\s+", " ").Trim() -match "LISTENING") {
            return $true
        }
    }

    return $false
}

if (Test-PortListening -PortToCheck $Port) {
    Write-Host "Port $Port is already in use. Assuming Octane is already running." -ForegroundColor Yellow
    exit 0
}

Write-Host "Starting Octane on http://127.0.0.1:$Port ..." -ForegroundColor Cyan

php artisan octane:start `
    --server=roadrunner `
    --host=127.0.0.1 `
    --port=$Port `
    --workers=$Workers `
    --task-workers=$TaskWorkers `
    --max-requests=$MaxRequests
