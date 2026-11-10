@echo off
setlocal
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" (
  echo Run START_SETUP.cmd first.
  pause
  exit /b 1
)
".venv\Scripts\python.exe" agent.py
echo.
echo Agent stopped. Close this window or press any key.
pause >nul
