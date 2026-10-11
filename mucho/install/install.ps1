param([Parameter(Mandatory=$true)][string]$GameDir)
$ErrorActionPreference = 'Stop'
$game = [IO.Path]::GetFullPath($GameDir)
if (!(Test-Path (Join-Path $game 'GeometryDash.exe') -PathType Leaf)) { throw 'Choose the folder containing GeometryDash.exe.' }
if (Get-Process GeometryDash -ErrorAction SilentlyContinue) { throw 'Close Geometry Dash before installing.' }
$manifest = Get-Content (Join-Path $PSScriptRoot 'package.json') -Raw | ConvertFrom-Json
$backup = Join-Path $game ('.mucholoader-backups/' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss-ffff'))
$plan = @()
foreach ($entry in $manifest.files) {
    $relative = [string]$entry.path
    if ($relative -match '(^/|\\|(^|/)\.\.?(/|$)|:)' -or $relative -notmatch '^(Geode\.dll|XInput1_4\.dll|GeodeUpdater\.exe|python[0-9]+\.dll|vcruntime[^/]*\.dll|geode/(resources/geode\.loader|mucholoader)/.+)$') { throw 'Invalid package path.' }
    $source = Join-Path (Join-Path $PSScriptRoot 'payload') $relative
    $target = [IO.Path]::GetFullPath((Join-Path $game $relative))
    if (Test-Path $target -PathType Container) { throw "Package target is a directory: $relative" }
    if (!(Test-Path $source -PathType Leaf) -or (Get-FileHash $source -Algorithm SHA256).Hash.ToLowerInvariant() -ne $entry.sha256) { throw "Package checksum mismatch: $relative" }
    # Refuse reparse points anywhere on the destination path, including the game folder.
    $cursor = $target
    while ($cursor) {
        if (Test-Path $cursor) {
            if ((Get-Item $cursor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Installation through a junction or symlink is unsupported.' }
        }
        $parent = Split-Path $cursor -Parent
        if ($parent -eq $cursor) { break }; $cursor = $parent
    }
    $plan += [pscustomobject]@{ path=$relative; source=$source; target=$target; existed=(Test-Path $target -PathType Leaf) }
}
if (!(Test-Path $game -PathType Container)) { throw 'Game folder is missing.' }
New-Item $backup -ItemType Directory -Force | Out-Null
$plan | Select-Object path,existed | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $backup 'restore.json') -Encoding UTF8
$changed = @()
# Complete the backup before replacing any file.
foreach ($item in $plan) {
    if ($item.existed) {
        $saved = Join-Path $backup $item.path
        New-Item (Split-Path $saved -Parent) -ItemType Directory -Force | Out-Null
        Copy-Item $item.target $saved
    }
}
try {
    foreach ($item in $plan) {
        New-Item (Split-Path $item.target -Parent) -ItemType Directory -Force | Out-Null
        $temporary = $item.target + '.mucho-install.tmp'
        Copy-Item $item.source $temporary -Force
        $changed += $item
        Move-Item $temporary $item.target -Force
    }
    Write-Host "MuchoLoader 0.1.0 installed. Backup: $backup"
} catch {
    foreach ($item in $changed) {
        if ($item.existed) { Copy-Item (Join-Path $backup $item.path) $item.target -Force }
        else { Remove-Item $item.target -Force -ErrorAction SilentlyContinue }
        Remove-Item ($item.target + '.mucho-install.tmp') -Force -ErrorAction SilentlyContinue
    }
    throw
}
