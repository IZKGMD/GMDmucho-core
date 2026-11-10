@echo off
setlocal
cd /d "%~dp0"
where py >nul 2>nul
if errorlevel 1 (
  echo Python 3.11+ is required. Install it from https://www.python.org/downloads/windows/
  pause
  exit /b 1
)
if not exist ".venv\Scripts\python.exe" (
  py -3 -m venv .venv
  if errorlevel 1 goto failed
)
".venv\Scripts\python.exe" -m pip install --upgrade pip
if errorlevel 1 goto failed
".venv\Scripts\python.exe" -m pip install -r windows_requirements.txt
if errorlevel 1 goto failed
".venv\Scripts\python.exe" agent_setup.py
if errorlevel 1 goto failed
echo.
echo Setup complete. Run START_AGENT.cmd to connect.
pause
exit /b 0
:failed
echo Setup failed. Read the error above and retry.
pause
exit /b 1
