$ErrorActionPreference = 'Stop'
$root = Join-Path ([IO.Path]::GetTempPath()) ('Mucho launch test ' + [Guid]::NewGuid())
New-Item -ItemType Directory -Path $root | Out-Null
try {
    $repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    Copy-Item (Join-Path $repo 'tools/client/Start-MuchoCore.ps1') $root
    New-Item -ItemType Directory -Path (Join-Path $root 'Resources') | Out-Null
    $exe = Join-Path $root 'GeometryDash.exe'
    Set-Content -LiteralPath $exe -Value 'Synthetic preflight fixture. Must never execute.'
    foreach ($name in @('msvcp140.dll', 'vcruntime140.dll', 'vcruntime140_1.dll')) {
        Set-Content -LiteralPath (Join-Path $root $name) -Value 'Fixture presence only'
    }
    $manifest = @{server_url='https://example.com'; files=@(@{name='GeometryDash.exe';sha256=(Get-FileHash $exe -Algorithm SHA256).Hash.ToLowerInvariant()})}
    $manifest | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $root 'client-verification.json')
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'Start-MuchoCore.ps1') -CheckOnly
    if ($LASTEXITCODE -ne 0) { throw 'Valid launch preflight failed.' }
    $log = Get-Content (Join-Path $root 'muchocore-launch.log') -Raw
    if ($log -notmatch 'without starting the game') { throw 'Check-only mode did not stop before launch.' }
    Add-Content -LiteralPath $exe -Value 'Changed bytes'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'Start-MuchoCore.ps1') -CheckOnly
    if ($LASTEXITCODE -ne 1) { throw 'Changed EXE was accepted.' }
    $log = Get-Content (Join-Path $root 'muchocore-launch.log') -Raw
    if ($log -notmatch 'differs from this package') { throw 'Missing actionable checksum diagnostic.' }
    Write-Host 'WINDOWS_START_PREFLIGHT_OK'
    exit 0
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force
}
