@echo off
REM Registers the nightly invoice auto-approval with Windows Task Scheduler.
REM Right-click this file and choose "Run as administrator" (once).
REM
REM The task runs every 15 minutes as SYSTEM, so it needs no logged-in user and
REM keeps running after a restart. cron\auto-approve.php does nothing unless
REM the nightly run is due (time and on/off: admin/settings.php?tab=access).

set "PHP_EXE=%~dp0..\..\..\php\php.exe"
set "SCRIPT=%~dp0auto-approve.php"

if not exist "%PHP_EXE%" (
    echo php.exe not found at %PHP_EXE%
    pause
    exit /b 1
)

schtasks /Create /F /TN "Lezan\AutoApproveInvoices" /SC MINUTE /MO 15 /RU SYSTEM /RL HIGHEST /TR "\"%PHP_EXE%\" \"%SCRIPT%\""
if errorlevel 1 (
    echo.
    echo FAILED - run this file as administrator.
) else (
    echo.
    echo OK - task "Lezan\AutoApproveInvoices" registered.
    "%PHP_EXE%" "%SCRIPT%" --status
)
pause
