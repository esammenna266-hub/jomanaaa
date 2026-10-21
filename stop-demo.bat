@echo off
chcp 65001 >nul
echo Stopping the Gomana demo...
powershell -NoProfile -Command "foreach ($p in 8000, 8080, 5173) { Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue } }"
echo Done.
timeout /t 3 >nul
