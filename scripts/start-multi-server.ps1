# Start three Laravel worker nodes for Task 5 multi-port demo.
# Prerequisites: php artisan migrate, CACHE_STORE=database in .env
#
# Usage (from project root):
#   .\scripts\start-multi-server.ps1

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

Write-Host "Starting 3 Laravel nodes (ports 8000, 8001, 8002)..." -ForegroundColor Cyan
Write-Host "Project: $ProjectRoot"
Write-Host "Use CACHE_STORE=database so Round Robin state is shared."
Write-Host ""

$nodes = @(
    @{ Id = 'server-1'; Port = 8000 },
    @{ Id = 'server-2'; Port = 8001 },
    @{ Id = 'server-3'; Port = 8002 }
)

foreach ($node in $nodes) {
    $id = $node.Id
    $port = $node.Port
    $cmd = "`$env:APP_NODE_ID='$id'; `$env:APP_NODE_PORT='$port'; Set-Location '$ProjectRoot'; php artisan serve --port=$port"

    Start-Process powershell -ArgumentList @(
        '-NoExit',
        '-Command',
        $cmd
    ) | Out-Null

    Write-Host "Started $id on http://127.0.0.1:$port/process" -ForegroundColor Green
}

Write-Host ""
Write-Host "In another terminal, run:" -ForegroundColor Yellow
Write-Host "  php artisan load:multi-server --tasks=12 --mode=balanced --reset"
Write-Host ""
Write-Host "Expected output:" -ForegroundColor Yellow
Write-Host "  Task 1 -> Handled by node on port 8000"
Write-Host "  Task 2 -> Handled by node on port 8001"
Write-Host "  Task 3 -> Handled by node on port 8002"
Write-Host "  Task 4 -> Handled by node on port 8000"
