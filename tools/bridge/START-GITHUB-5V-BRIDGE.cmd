@echo off
setlocal
title MuchoCore 5v.pl - GitHub FTP Bridge Setup
echo.
echo MuchoCore 5v.pl: setup with GitHub account and your hosting credentials.
echo This launcher downloads the public source from your MuchoCore repository.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $path=Join-Path $env:TEMP 'MuchoCore-GitHub-5V-Setup.ps1'; try { [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -UseBasicParsing -Uri 'https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/tools/bridge/Setup-GitHub-5V-Bridge.ps1' -OutFile $path; & $path; if (-not $?) { exit 1 } } catch { Write-Host ('SETUP ERROR: ' + $_.Exception.Message) -ForegroundColor Red; exit 1 } finally { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }"
if errorlevel 1 (
  echo.
  echo Setup did not finish. Check the error above.
  pause
  exit /b 1
)
echo.
echo Setup completed. Check the linked GitHub Issue for the result.
pause
