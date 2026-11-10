@echo off
setlocal
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel% equ 0 (
  py -3 INSTALL_5V.py --advanced
) else (
  where python >nul 2>nul
  if %errorlevel% equ 0 (
    python INSTALL_5V.py --advanced
  ) else (
    echo Python 3.10 or newer is required.
    echo Install Python from https://www.python.org/downloads/windows/
  )
)
echo.
pause
