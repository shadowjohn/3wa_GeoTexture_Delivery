@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
  echo PHP was not found. Add PHP 8.2+ to PATH first.
  pause
  exit /b 1
)
echo GeoTexture Delivery: http://127.0.0.1:8088
echo Login: admin / admin
echo Press Ctrl+C to stop.
start "" "http://127.0.0.1:8088"
php -S 127.0.0.1:8088 -t "%~dp0public"
