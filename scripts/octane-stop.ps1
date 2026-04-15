param(
    [int]$Port = 8010
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Write-Host "Stopping Octane (RoadRunner)..." -ForegroundColor Cyan

try {
    php artisan octane:stop --server=roadrunner | Out-Host
} catch {
    Write-Host "octane:stop failed, continuing with force cleanup..." -ForegroundColor Yellow
}

Start-Sleep -Milliseconds 600

$listeningPids = @()
$lines = netstat -ano | Select-String ":$Port"
foreach ($line in $lines) {
    $text = ($line.ToString() -replace "\s+", " ").Trim()
    if ($text -notmatch "LISTENING") {
        continue
    }

    $parts = $text.Split(" ")
    if ($parts.Length -lt 5) {
        continue
    }

    $procId = 0
    if ([int]::TryParse($parts[-1], [ref]$procId) -and $procId -gt 0) {
        $listeningPids += $procId
    }
}

$listeningPids = @($listeningPids | Sort-Object -Unique)

if ($listeningPids.Count -eq 0) {
    Write-Host "Port $Port is free." -ForegroundColor Green
    exit 0
}

foreach ($procId in $listeningPids) {
    Write-Host "Force killing PID $procId on port $Port..." -ForegroundColor Yellow
    taskkill /PID $procId /F | Out-Null
}

Start-Sleep -Milliseconds 400

$stillBusy = netstat -ano | Select-String ":$Port"
if ($stillBusy) {
    Write-Host "Port $Port is still busy. Please close remaining process manually." -ForegroundColor Red
    exit 1
}

Write-Host "Port $Port successfully freed." -ForegroundColor Green
exit 0
