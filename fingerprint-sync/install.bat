@echo off
REM Lezan - fingerprint sync: install as an always-on background task.
REM
REM Right-click this file and choose "Run as administrator" (once).
REM
REM 1. Finds Python 3.9+ (the py launcher, Program Files, the user's own
REM    install - never the Microsoft Store shortcut, which cannot run as a
REM    background task). If there is none, installs Python 3.12 for all
REM    users with winget.
REM 2. Creates .venv here and installs pyzk + PyMySQL.
REM 3. Tests the device and the Lezan database, and asks before starting.
REM 4. Registers two tasks that run as SYSTEM (no login needed):
REM      Lezan\FingerprintSync          starts with the computer
REM      Lezan\FingerprintSyncWatchdog  every 5 minutes; restarts it if it stopped
REM    Only one copy ever runs (the first holds a local port).
setlocal EnableExtensions
cd /d "%~dp0"

net session >nul 2>&1
if errorlevel 1 (
    echo Please right-click install.bat and choose "Run as administrator".
    pause
    exit /b 1
)

call :find_python
if not defined PYEXE (
    echo Python is not installed on this computer.
    where winget >nul 2>&1
    if not errorlevel 1 (
        echo Installing Python 3.12 for all users with winget - this takes a minute ...
        winget install -e --id Python.Python.3.12 --scope machine --silent --accept-package-agreements --accept-source-agreements
        call :find_python
    )
)
if not defined PYEXE (
    echo.
    echo Python could not be installed automatically.
    echo Download Python 3.12 from https://www.python.org/downloads/
    echo During setup tick "Add python.exe to PATH", then run install.bat again.
    pause
    exit /b 1
)
echo Using Python: %PYEXE%

if not exist ".venv\Scripts\python.exe" (
    echo Creating .venv ...
    "%PYEXE%" -m venv .venv
    if errorlevel 1 (
        echo Could not create .venv
        pause
        exit /b 1
    )
)
echo Installing pyzk and PyMySQL ...
".venv\Scripts\python.exe" -m pip install --quiet --upgrade pip setuptools wheel
".venv\Scripts\python.exe" -m pip install --quiet -r requirements.txt
if errorlevel 1 (
    echo pip install failed - check the internet connection and run this file again.
    pause
    exit /b 1
)

echo.
echo Checking the device and the Lezan database ...
".venv\Scripts\python.exe" zk_sync.py --check
echo.
echo From now on every punch on the device (from start_from in config.ini) is
echo copied into the Lezan database above, by the device's PIN. Make sure each
echo PIN on the device is the same employee's PIN in Lezan.
choice /C YN /M "Install and start the fingerprint sync now"
if errorlevel 2 (
    echo Cancelled - nothing was installed.
    pause
    exit /b 1
)

REM Stop a running copy so the new code is the one that runs.
schtasks /End /TN "Lezan\FingerprintSync" >nul 2>&1
schtasks /End /TN "Lezan\FingerprintSyncWatchdog" >nul 2>&1

set "TR=\"%~dp0.venv\Scripts\pythonw.exe\" \"%~dp0zk_sync.py\""
schtasks /Create /F /TN "Lezan\FingerprintSync" /SC ONSTART /RU SYSTEM /RL HIGHEST /TR "%TR%"
if errorlevel 1 goto failed
schtasks /Create /F /TN "Lezan\FingerprintSyncWatchdog" /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /TR "%TR%"
if errorlevel 1 goto failed
schtasks /Run /TN "Lezan\FingerprintSync" >nul

echo.
echo OK - the fingerprint sync is installed and running.
echo It starts by itself with the computer. Log: %~dp0logs\sync.log
echo The HR dashboard in Lezan shows the device status.
pause
exit /b 0

:failed
echo.
echo FAILED to register the scheduled task - run this file as administrator.
pause
exit /b 1


REM ---------------------------------------------------------------------
REM Sets PYEXE to a real python.exe 3.9+, or leaves it empty.
:find_python
set "PYEXE="
REM 1) the py launcher that the python.org installer adds
for /f "delims=" %%P in ('py -3 -c "import sys; print(sys.executable)" 2^>nul') do set "PYEXE=%%P"
if defined PYEXE goto :check_python
REM 2) the usual install folders (all users, then this user)
for /d %%D in ("%ProgramFiles%\Python3*" "%LocalAppData%\Programs\Python\Python3*") do (
    if exist "%%~D\python.exe" set "PYEXE=%%~D\python.exe"
)
if defined PYEXE goto :check_python
REM 3) python on PATH - but not the Microsoft Store shortcut
for /f "delims=" %%P in ('where python 2^>nul ^| findstr /v /i "WindowsApps"') do (
    if not defined PYEXE set "PYEXE=%%P"
)
:check_python
if not defined PYEXE exit /b 0
echo %PYEXE% | findstr /i "WindowsApps" >nul && set "PYEXE="
if not defined PYEXE exit /b 0
"%PYEXE%" -c "import sys; sys.exit(0 if sys.version_info >= (3, 9) else 1)" >nul 2>&1
if errorlevel 1 set "PYEXE="
exit /b 0
