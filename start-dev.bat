@echo off
echo ========================================
echo    Starting Time Tracker Application
echo ========================================
echo.

echo Starting Laravel Server...
start "Laravel Server" cmd /k "cd /d %~dp0 && php artisan serve"
timeout /t 2 /nobreak >nul

echo Starting Vite Dev Server...
start "Vite Dev Server" cmd /k "cd /d %~dp0 && npm run dev"
timeout /t 2 /nobreak >nul

echo.
echo ========================================
echo    SERVERS STARTED!
echo ========================================
echo.
echo Your application will be available at:
echo http://localhost:8000
echo.
echo Press any key to open browser...
pause >nul

start http://localhost:8000

echo.
echo ========================================
echo Both terminal windows must stay open!
echo Close this window when done.
echo ========================================
