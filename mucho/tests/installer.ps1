$ErrorActionPreference = 'Stop'
$root = Join-Path ([IO.Path]::GetTempPath()) ('mucho-install-test-' + [guid]::NewGuid())
$game = Join-Path $root 'game'
$dist = Join-Path $root 'dist'
New-Item $game,$dist -ItemType Directory -Force | Out-Null
function Assert($value,$message) { if (!$value) { throw $message } }
try {
    Set-Content (Join-Path $game 'GeometryDash.exe') 'fixture only'
    Set-Content (Join-Path $game 'Geode.dll') 'old loader'
    New-Item (Join-Path $game 'geode/mods') -ItemType Directory -Force | Out-Null
    Set-Content (Join-Path $game 'geode/mods/existing.geode') 'native mod fixture'
    $payload = Join-Path $dist 'payload'
    New-Item (Join-Path $payload 'geode/mucholoader/sdk/mucho') -ItemType Directory -Force | Out-Null
    Set-Content (Join-Path $payload 'Geode.dll') 'new loader'
    Set-Content (Join-Path $payload 'XInput1_4.dll') 'new proxy'
    Set-Content (Join-Path $payload 'geode/mucholoader/sdk/mucho/__init__.py') '# sdk'
    Copy-Item (Join-Path $PSScriptRoot '../install/install.ps1') $dist
    Copy-Item (Join-Path $PSScriptRoot '../install/restore.ps1') $dist
    $files = @(Get-ChildItem $payload -File -Recurse | ForEach-Object {
        @{path=[IO.Path]::GetRelativePath($payload,$_.FullName).Replace('\','/');sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
    })
    @{version='0.1.0';files=$files} | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $dist 'package.json')
    & (Join-Path $dist 'install.ps1') -GameDir $game
    Assert ((Get-Content (Join-Path $game 'Geode.dll') -Raw).Trim() -eq 'new loader') 'Loader replaced'
    Assert ((Get-Content (Join-Path $game 'geode/mods/existing.geode') -Raw).Trim() -eq 'native mod fixture') 'Native mod preserved'
    $backup = Get-ChildItem (Join-Path $game '.mucholoader-backups') -Directory | Select-Object -First 1
    & (Join-Path $dist 'restore.ps1') -GameDir $game -BackupDir $backup.FullName
    Assert ((Get-Content (Join-Path $game 'Geode.dll') -Raw).Trim() -eq 'old loader') 'Previous loader restored'
    Assert (!(Test-Path (Join-Path $game 'XInput1_4.dll'))) 'New files removed on restore'
    New-Item (Join-Path $game 'geode/update') -ItemType Directory -Force | Out-Null
    $failed=$false
    try { & (Join-Path $dist 'install.ps1') -GameDir $game } catch { $failed=$true }
    Assert $failed 'Pending upstream replacement refused'
    Assert ((Get-Content (Join-Path $game 'Geode.dll') -Raw).Trim() -eq 'old loader') 'Pending update causes no writes'
    Remove-Item (Join-Path $game 'geode/update') -Recurse
    Set-Content (Join-Path $payload 'Geode.dll') 'tampered'
    $failed = $false
    try { & (Join-Path $dist 'install.ps1') -GameDir $game } catch { $failed=$true }
    Assert $failed 'Checksum tampering refused'
    Assert ((Get-Content (Join-Path $game 'Geode.dll') -Raw).Trim() -eq 'old loader') 'Tampering causes no writes'
    @{version='0.1.0';files=@(@{path='../escape';sha256='bad'})} | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $dist 'package.json')
    $failed=$false
    try { & (Join-Path $dist 'install.ps1') -GameDir $game } catch { $failed=$true }
    Assert $failed 'Traversal refused'
    Write-Host 'PASS: install/restore, native preservation, pending update, checksums and traversal'
} finally { Remove-Item $root -Recurse -Force -ErrorAction SilentlyContinue }
