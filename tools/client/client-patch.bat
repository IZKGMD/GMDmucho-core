@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "PATCHER=%~dp0client-patch.py"
rem Every GDPS owner chooses their own HTTPS domain; never bake our host
rem into a public client-patching tool. MUCHO_GDPS_URL allows automation.
set "SERVER="
if defined MUCHO_GDPS_URL set "SERVER=%MUCHO_GDPS_URL%"
if not defined SERVER (
  echo.
  echo Enter YOUR GDPS address, e.g. https://example.5v.pl
  echo Use the domain root, not /database/ or /a/
  set /p "SERVER=GDPS HTTPS URL: "
)
if not defined SERVER (
  echo ERROR: You must enter your own HTTPS GDPS URL.
  pause
  exit /b 2
)
if /i not "%SERVER:~0,8%"=="https://" (
  if "%SERVER:~0,1%"=="h" (
    echo ERROR: HTTPS required. Enter https://your-domain
    pause
    exit /b 2
  )
  set "SERVER=https://%SERVER%"
)

if not exist "%PATCHER%" (
  echo ERROR: client-patch.py was not found.
  pause
  exit /b 1
)

where py.exe >nul 2>nul
if not errorlevel 1 (
  set "PYTHON=py.exe -3"
  goto run
)

where python.exe >nul 2>nul
if not errorlevel 1 (
  set "PYTHON=python.exe"
  goto run
)

echo ERROR: Python 3 was not found.
echo Install Python 3 and make sure "py" or "python" is available in PATH.
pause
exit /b 1

:run
if "%~1"=="" (
  %PYTHON% "%PATCHER%" --server-url "%SERVER%"
) else (
  %PYTHON% "%PATCHER%" --input "%~1" --server-url "%SERVER%"
)

set "RC=%ERRORLEVEL%"
echo.
if "%RC%"=="0" (
  echo MuchoCore patch completed successfully.
  echo Server: %SERVER%
) else (
  echo The patch was NOT completed. Exit code: %RC%
)
echo.
pause
exit /b %RC%
