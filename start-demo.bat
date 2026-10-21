@echo off
chcp 65001 >nul
title Gomana demo
cd /d "%~dp0"

echo.
echo  === Gomana demo ===
echo.

rem MySQL must be running (it is a Windows service and normally starts with the computer).
powershell -NoProfile -Command "if (-not (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue)) { exit 1 }"
if errorlevel 1 (
  echo  MySQL is not running. Start the MySQL service first ^(Services ^> MySQL ^> Start^), then run this again.
  pause
  exit /b 1
)

rem Start each part in its own minimised window. Closing a window stops that part.
start "Gomana API" /min cmd /c "cd /d "%~dp0backend" && php artisan serve --port=8000"
start "Gomana live updates" /min cmd /c "cd /d "%~dp0backend" && php artisan reverb:start --port=8080"
start "Gomana web app" /min cmd /c "cd /d "%~dp0frontend" && npm run dev -- --port=5173 --strictPort"

echo  Starting... please wait.
powershell -NoProfile -Command "$until = (Get-Date).AddSeconds(60); do { Start-Sleep -Milliseconds 700; $up = (Get-NetTCPConnection -LocalPort 8000,5173 -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty LocalPort -Unique).Count -eq 2 } until ($up -or (Get-Date) -gt $until); if (-not $up) { exit 1 }"
if errorlevel 1 (
  echo  The servers did not start in time. Close the minimised windows, then run this again.
  pause
  exit /b 1
)

if not defined GOMANA_NO_BROWSER start "" http://localhost:5173

echo.
echo  Ready:  http://localhost:5173
echo  Logins: backend\DEMO_ACCOUNTS.local.txt   ^(owner = the owner, sara = a branch employee, alnoor = an external office^)
echo  To stop everything, run stop-demo.bat
echo.
timeout /t 6 >nul
