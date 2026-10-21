@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0run_project.ps1" %*
set "PROJECT_EXIT_CODE=%ERRORLEVEL%"
if not "%PROJECT_EXIT_CODE%"=="0" (
    echo.
    echo Setup or startup failed. Read the error above.
    pause
)
exit /b %PROJECT_EXIT_CODE%
