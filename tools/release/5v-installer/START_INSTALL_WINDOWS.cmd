@echo off
setlocal
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel% equ 0 (
  py -3 INSTALL_5V.py
) else (
  where python >nul 2>nul
  if %errorlevel% equ 0 (
    python INSTALL_5V.py
  ) else (
    echo.
    echo Python 3.10 or newer is required.
    echo Install Python from https://www.python.org/downloads/windows/
    echo Tick "Add python.exe to PATH" and run this file again.
    echo.
  )
)
echo.
pause
