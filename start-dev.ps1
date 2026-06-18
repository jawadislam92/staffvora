# Time Tracker - Development Server Startup Script

Write-Host "`n========================================" -ForegroundColor Green
Write-Host "   Starting Time Tracker" -ForegroundColor Green
Write-Host "========================================`n" -ForegroundColor Green

# Check if we're in the right directory
if (-not (Test-Path "artisan")) {
    Write-Host "ERROR: Please run this script from the project root directory!" -ForegroundColor Red
    Write-Host "Current directory: $PWD`n" -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host "✓ Project directory verified" -ForegroundColor Cyan

# Start Laravel Server in new terminal
Write-Host "`nStarting Laravel Server (port 8000)..." -ForegroundColor Yellow
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PWD'; php artisan serve"
Start-Sleep -Seconds 2

# Start Vite Dev Server in new terminal  
Write-Host "Starting Vite Dev Server (port 5175)..." -ForegroundColor Yellow
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PWD'; npm run dev"
Start-Sleep -Seconds 3

Write-Host "`n========================================" -ForegroundColor Green
Write-Host "   SERVERS STARTED!" -ForegroundColor Green
Write-Host "========================================`n" -ForegroundColor Green

Write-Host "Your application is starting at:" -ForegroundColor White
Write-Host "http://localhost:8000`n" -ForegroundColor Cyan

Write-Host "Two terminal windows have opened:" -ForegroundColor Yellow
Write-Host "  1. Laravel Server (port 8000)" -ForegroundColor White
Write-Host "  2. Vite Dev Server (port 5175)" -ForegroundColor White
Write-Host "`nKeep both terminals open while developing!`n" -ForegroundColor Yellow

# Wait a moment then open browser
Write-Host "Opening browser in 5 seconds..." -ForegroundColor Cyan
Start-Sleep -Seconds 5

Start-Process "http://localhost:8000"

Write-Host "`n✓ Browser opened!" -ForegroundColor Green
Write-Host "`nTo stop servers:" -ForegroundColor Yellow
Write-Host "  - Close both terminal windows" -ForegroundColor White
Write-Host "  - Or press Ctrl+C in each terminal`n" -ForegroundColor White

Write-Host "Press any key to close this window..." -ForegroundColor Gray
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
