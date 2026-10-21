@echo off
setlocal EnableExtensions DisableDelayedExpansion
title MuchoCore - Client Patchers
:menu
cls
echo ============================================
echo  MUCHOCORE - PATCHERS FOR GEOMETRY DASH
echo ============================================
echo.
echo   [1] Windows EXE - local graphical patcher
echo       Needs Python 3 and your original game EXE
echo.
echo   [2] Android APK - open the admin APK patcher
echo       Needs a full PHP 8.2+ MuchoCore admin
echo.
echo   [3] Instructions and troubleshooting
echo.
echo   [0] Exit
echo.
choice /C 1230 /N /M "Choose 1, 2, 3 or 0: "
if errorlevel 4 exit /b 0
if errorlevel 3 (
    start "" "%~dp0README.md"
    goto menu
)
if errorlevel 2 (
    call "%~dp0android\OPEN-ANDROID.cmd"
    goto menu
)
if errorlevel 1 (
    call "%~dp0windows\START-WINDOWS.cmd"
    goto menu
)
goto menu
