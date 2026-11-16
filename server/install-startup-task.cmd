@echo off
rem ============================================================================
rem  Lezan - start the system every time this user logs on to Windows.
rem  Adds a Task Scheduler task "Lezan Server" that runs start-lezan.cmd.
rem  To remove it:   schtasks /Delete /TN "Lezan Server" /F
rem ============================================================================
schtasks /Create /TN "Lezan Server" /TR "\"%~dp0start-lezan.cmd\"" /SC ONLOGON /RL LIMITED /F
if errorlevel 1 (
    echo Could not add the task.
) else (
    echo Added: Lezan starts at every logon.
)
pause
