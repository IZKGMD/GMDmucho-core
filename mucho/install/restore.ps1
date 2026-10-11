param([Parameter(Mandatory=$true)][string]$GameDir,[Parameter(Mandatory=$true)][string]$BackupDir)
$ErrorActionPreference = 'Stop'
$game = [IO.Path]::GetFullPath($GameDir)
$backup = [IO.Path]::GetFullPath($BackupDir)
if (!(Test-Path (Join-Path $game 'GeometryDash.exe'))) { throw 'Game folder is invalid.' }
if (Get-Process GeometryDash -ErrorAction SilentlyContinue) { throw 'Close Geometry Dash first.' }
$entries = Get-Content (Join-Path $backup 'restore.json') -Raw | ConvertFrom-Json
foreach ($entry in $entries) {
    $relative = [string]$entry.path
    if ($relative -match '(^/|\\|(^|/)\.\.?(/|$)|:)' -or $relative -notmatch '^(Geode\.dll|XInput1_4\.dll|GeodeUpdater\.exe|python[0-9]+\.dll|vcruntime[^/]*\.dll|geode/(resources/geode\.loader|mucholoader)/.+)$') { throw 'Invalid backup path.' }
    $target = Join-Path $game $relative
    if ($entry.existed) {
        $source = Join-Path $backup $relative
        if (!(Test-Path $source -PathType Leaf)) { throw 'Backup is incomplete.' }
    }
}
foreach ($entry in $entries) {
    $target = Join-Path $game $entry.path
    if ($entry.existed) { Copy-Item (Join-Path $backup $entry.path) $target -Force }
    else { Remove-Item $target -Force -ErrorAction SilentlyContinue }
}
Write-Host 'Previous loader files restored. Native mods and save data were preserved.'
