@echo off
setlocal
cd /d "%~dp0"
where py >nul 2>nul
if errorlevel 1 (
  echo Python 3 was not found.
  echo Install Python 3 from https://www.python.org/ and run this file again.
  pause
  exit /b 1
)
py "%~dp0client-patch.py"
if errorlevel 1 (
  echo.
  echo The patch was not completed.
  pause
  exit /b 1
)
echo.
echo Done. Press any key to close.
pause >nul
