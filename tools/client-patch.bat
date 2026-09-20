@echo off
setlocal
cd /d "%~dp0"

where powershell.exe >nul 2>nul
if errorlevel 1 (
  echo Windows PowerShell was not found.
  echo This tool requires Windows PowerShell.
  pause
  exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0client-patcher.ps1"
if errorlevel 1 (
  echo.
  echo The patch was not completed.
  pause
  exit /b 1
)

echo.
echo Done. Press any key to close.
pause >nul
