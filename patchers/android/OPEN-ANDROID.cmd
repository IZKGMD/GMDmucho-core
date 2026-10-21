@echo off
setlocal EnableExtensions DisableDelayedExpansion
title MuchoCore - Open Android APK Patcher
echo.
echo MuchoCore Android APK Patcher (full PHP 8.2+ admin)
echo An Android APK cannot be patched by the Windows EXE tool.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0open-android.ps1"
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
  echo.
  echo Failed to open admin patcher. See error above.
)
pause
exit /b %RC%
