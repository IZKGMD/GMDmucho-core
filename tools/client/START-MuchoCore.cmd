@echo off
setlocal
cd /d "%~dp0"
set "MUCHO_POWERSHELL=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
if exist "%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe" set "MUCHO_POWERSHELL=%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe"
"%MUCHO_POWERSHELL%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-MuchoCore.ps1" %*
exit /b %errorlevel%
