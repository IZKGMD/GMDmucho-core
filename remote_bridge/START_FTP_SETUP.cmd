@echo off
setlocal
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" (
  echo Run START_SETUP.cmd first.
  pause
  exit /b 1
)
".venv\Scripts\python.exe" configure_ftp.py
pause
