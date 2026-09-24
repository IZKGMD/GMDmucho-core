@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "PATCHER=%~dp0client-patch.py"
set "SERVER=https://muchogdps.space"

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
