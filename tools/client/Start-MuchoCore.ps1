param([switch]$CheckOnly)
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$log = Join-Path $root 'muchocore-launch.log'
function Write-LaunchLog([string]$text) {
    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $text
    Add-Content -LiteralPath $log -Value $line -Encoding UTF8
    Write-Host $text
}
try {
    Set-Content -LiteralPath $log -Value 'MuchoCore Windows launch diagnostics' -Encoding UTF8
    if (-not [Environment]::Is64BitOperatingSystem) { throw 'This client requires 64-bit Windows.' }
    if ([IntPtr]::Size -ne 8) { throw 'Run START-MuchoCore.cmd using 64-bit Windows PowerShell.' }
    $exe = Join-Path $root 'GeometryDash.exe'
    $manifestPath = Join-Path $root 'client-verification.json'
    if (-not (Test-Path -LiteralPath $manifestPath)) { throw 'client-verification.json is missing. Extract the complete client archive first.' }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    Write-LaunchLog ('Server: ' + $manifest.server_url)
    Write-LaunchLog ('Working folder: ' + $root)
    foreach ($entry in $manifest.files) {
        if ($entry.name -ne [IO.Path]::GetFileName($entry.name)) { throw 'Invalid client verification filename.' }
        $file = Join-Path $root $entry.name
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw ('Missing game file: ' + $entry.name) }
        $actual = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actual -ne $entry.sha256) { throw ('Game file differs from this package: ' + $entry.name) }
    }
    if (-not (Test-Path -LiteralPath (Join-Path $root 'Resources') -PathType Container)) {
        throw 'Resources is missing. Do not run the EXE alone or from inside the ZIP.'
    }
    foreach ($dll in @('msvcp140.dll', 'vcruntime140.dll', 'vcruntime140_1.dll')) {
        if (-not ((Test-Path -LiteralPath (Join-Path $root $dll)) -or
                  (Test-Path -LiteralPath (Join-Path ([Environment]::SystemDirectory) $dll)))) {
            throw ('Missing Microsoft Visual C++ x64 runtime: ' + $dll + '. Install the official Microsoft Visual C++ v14 x64 Redistributable, then retry.')
        }
    }
    Write-LaunchLog 'EXE, matching game DLLs, Resources and runtime files are present.'
    if ($CheckOnly) { Write-LaunchLog 'Check completed without starting the game.'; exit 0 }
    if (Get-Process -Name GeometryDash -ErrorAction SilentlyContinue) {
        throw 'GeometryDash.exe is already running. Close the existing game before starting this copy.'
    }
    if (-not (Get-Process -Name steam -ErrorAction SilentlyContinue)) {
        Write-LaunchLog 'Steam is not running. This package preserves the original Steam integration; Steam/account ownership checks are unchanged.'
    }
    $started = Get-Date
    Write-LaunchLog 'Starting GeometryDash.exe...'
    $process = Start-Process -FilePath $exe -WorkingDirectory $root -PassThru `
        -RedirectStandardOutput (Join-Path $root 'muchocore-game-stdout.log') `
        -RedirectStandardError (Join-Path $root 'muchocore-game-stderr.log')
    Write-LaunchLog ('Process ID: ' + $process.Id)
    $process.WaitForExit()
    $process.Refresh()
    $seconds = [Math]::Round(((Get-Date) - $started).TotalSeconds, 1)
    Write-LaunchLog ('Game exited with code {0} after {1} seconds.' -f $process.ExitCode, $seconds)
    if ($process.ExitCode -ne 0 -or $seconds -lt 8) {
        try {
            $events = Get-WinEvent -FilterHashtable @{LogName='Application'; StartTime=$started; Id=1000,1001} -MaxEvents 10 -ErrorAction Stop
            foreach ($event in $events) {
                if ($event.Message -match 'GeometryDash') { Add-Content -LiteralPath $log -Value $event.Message -Encoding UTF8 }
            }
        } catch { Write-LaunchLog 'No readable Windows crash event was available.' }
        throw 'The game closed early. See muchocore-launch.log and muchocore-game-stderr.log in this folder. No credentials or saves were changed.'
    }
    exit 0
} catch {
    Write-LaunchLog ('ERROR: ' + $_.Exception.Message)
    Write-Host ('Diagnostic log: ' + $log)
    if (-not $CheckOnly) { [void](Read-Host 'Press Enter to close') }
    exit 1
}
