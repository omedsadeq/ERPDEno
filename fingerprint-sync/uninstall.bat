@echo off
REM Lezan - fingerprint sync: stop it and remove the scheduled tasks.
REM Right-click and "Run as administrator". Punches already in Lezan stay.
schtasks /End /TN "Lezan\FingerprintSync" >nul 2>&1
schtasks /Delete /F /TN "Lezan\FingerprintSync"
schtasks /End /TN "Lezan\FingerprintSyncWatchdog" >nul 2>&1
schtasks /Delete /F /TN "Lezan\FingerprintSyncWatchdog"
echo.
echo Removed. The device is no longer read by Lezan.
pause
