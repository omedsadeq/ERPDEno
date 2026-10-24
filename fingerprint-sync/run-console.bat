@echo off
REM Lezan - fingerprint sync in this window, to watch it work (Ctrl+C stops).
REM The background task is stopped first, since only one copy can run; it
REM starts again with the computer, or within 5 minutes via the watchdog.
cd /d "%~dp0"
schtasks /End /TN "Lezan\FingerprintSync" >nul 2>&1
schtasks /End /TN "Lezan\FingerprintSyncWatchdog" >nul 2>&1
".venv\Scripts\python.exe" zk_sync.py
pause
