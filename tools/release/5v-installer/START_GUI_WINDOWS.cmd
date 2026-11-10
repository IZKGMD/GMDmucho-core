@echo off
setlocal
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel% equ 0 (
  py -3 GUI_SETUP_5V.py
) else (
  where python >nul 2>nul
  if %errorlevel% equ 0 (
    python GUI_SETUP_5V.py
  ) else (
    echo.
    echo Python is not installed.
    echo Download the standalone MuchoCore-Setup.exe from the release assets.
    echo The standalone installer does not require Python.
    echo.
    start "" "https://github.com/IZKGMD/GMDmucho-core/releases"
  )
)
echo.
pause
