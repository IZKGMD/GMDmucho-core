@echo off
setlocal EnableExtensions DisableDelayedExpansion
title MuchoCore - Geometry Dash 2.209 Launch Doctor
echo.
echo ===================================================
echo  MuchoCore: Find out why Geometry Dash will not run
echo ===================================================
echo.
echo  This is diagnostic ONLY. It does not remove Steam DRM,
echo  alter game files, change Windows settings or patch EXE.
echo  Run it on the computer where Geometry Dash fails.
echo.
if not exist "%~dp0LAUNCH-DOCTOR.ps1" (
  echo ERROR: Missing LAUNCH-DOCTOR.ps1
  pause
  exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0LAUNCH-DOCTOR.ps1" -Launch
set "RC=%ERRORLEVEL%"
echo.
echo Please send the report written to your Desktop.
echo Review paths and crash text before sharing publicly.
pause
exit /b %RC%
