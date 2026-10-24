@echo off
REM Lezan - fingerprint sync: is it running, can it reach the device and the
REM database, and the last lines of its log.
cd /d "%~dp0"
echo === Scheduled task ===
schtasks /Query /TN "Lezan\FingerprintSync" /FO LIST | findstr /i "Status Last Next"
echo.
echo === Device and database ===
if exist ".venv\Scripts\python.exe" (
    ".venv\Scripts\python.exe" zk_sync.py --check
) else (
    echo Not installed yet - run install.bat as administrator.
)
echo.
echo === Last 25 log lines (logs\sync.log) ===
if exist "logs\sync.log" powershell -NoProfile -Command "Get-Content -Encoding UTF8 -Tail 25 'logs\sync.log'"
pause
