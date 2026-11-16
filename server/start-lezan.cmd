@echo off
rem ============================================================================
rem  Lezan - start the system on this PC (MySQL + PHP web server).
rem
rem  The site listens on http://127.0.0.1:8080 only; the Cloudflare Tunnel
rem  (the "cloudflared" Windows service) carries it to the domain. Nothing is
rem  opened on the router or the firewall.
rem
rem  Run by hand, or at logon by the task server\install-startup-task.cmd adds.
rem  Each part is started only if it is not already running.
rem ============================================================================
setlocal
set "APP=%~dp0.."
set "PHP=%USERPROFILE%\.config\herd-lite\bin\php.exe"
set "MYSQLD=C:\MySQL\mysql-9.7.2-winx64\bin\mysqld.exe"
set "MYSQL_INI=C:\MySQL\my.ini"
set "PORT=8080"

rem -- MySQL ------------------------------------------------------------------
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if errorlevel 1 (
    echo Starting MySQL...
    start "Lezan MySQL" /MIN "%MYSQLD%" --defaults-file="%MYSQL_INI%" --console
    timeout /t 5 /nobreak >nul
) else (
    echo MySQL is already running.
)

rem -- PHP web server -----------------------------------------------------------
netstat -ano | find "127.0.0.1:%PORT% " | find "LISTENING" >nul
if errorlevel 1 (
    echo Starting the web server on 127.0.0.1:%PORT%...
    start "Lezan Web" /MIN "%PHP%" -S 127.0.0.1:%PORT% -t "%APP%" "%APP%\server\router.php"
) else (
    echo The web server is already running on port %PORT%.
)

echo.
echo Lezan is up: http://127.0.0.1:%PORT%
endlocal
