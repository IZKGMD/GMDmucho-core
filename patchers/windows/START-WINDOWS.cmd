@echo off
setlocal EnableExtensions DisableDelayedExpansion
title MuchoCore - Windows Client Patcher
set "CORE=%~dp0..\.."
set "GUI=%CORE%\tools\client\client-patcher.ps1"
set "ENGINE=%CORE%\tools\client\client-patch.py"
echo.
echo ==========================================
echo   MuchoCore - Windows Geometry Dash Patcher
echo ==========================================
echo.
echo 1. Choose your original GeometryDash.exe
echo 2. Enter YOUR HTTPS server root (without /mc or /database)
echo 3. Click Patch client - original EXE is kept safe
echo.
if not exist "%GUI%" (
    echo ERROR: Missing %GUI%
    echo Please download or clone the FULL MuchoCore repository.
    pause
    exit /b 1
)
if not exist "%ENGINE%" (
    echo ERROR: Missing %ENGINE%
    echo Please download or clone the FULL MuchoCore repository.
    pause
    exit /b 1
)
where powershell.exe >nul 2>nul
if errorlevel 1 (
    echo ERROR: Windows PowerShell was not found.
    pause
    exit /b 1
)
echo The GUI uses Python 3: install it if prompted.
echo.
if "%~1"=="" (
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%GUI%" -ServerUrl ""
) else (
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%GUI%" -ServerUrl "" -InputPath "%~f1"
)
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
    echo.
    echo Patcher window exited with error code %RC%.
    pause
)
exit /b %RC%
