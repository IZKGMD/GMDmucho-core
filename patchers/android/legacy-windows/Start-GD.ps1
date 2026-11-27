param(
    [ValidatePattern('^Nougat32(?:_[0-9]+)?$')]
    [string]$Instance = 'Nougat32',
    [switch]$Setup
)

$ErrorActionPreference = 'Stop'
try {
    $registry = Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\BlueStacks_nxt' -ErrorAction SilentlyContinue
    $directories = @($registry.InstallDir, (Join-Path $env:ProgramFiles 'BlueStacks_nxt'))
    $player = $null
    foreach ($directory in $directories) {
        if ($directory) {
            $candidate = Join-Path $directory 'HD-Player.exe'
            if (Test-Path -LiteralPath $candidate -PathType Leaf) {
                $player = $candidate
                break
            }
        }
    }
    if (-not $player) {
        throw 'Install BlueStacks 5 App Player and create a Nougat 32-bit instance. See README.txt.'
    }

    $apk = Join-Path $PSScriptRoot 'GeometryDash-MuchoCore.apk'
    if (-not (Test-Path -LiteralPath $apk -PathType Leaf)) {
        throw 'Extract the whole ZIP before running Start-GD.cmd.'
    }

    $configDirectories = @($registry.UserDefinedDir, $registry.DataDir, (Join-Path $env:ProgramData 'BlueStacks_nxt'))
    foreach ($directory in $configDirectories) {
        if (-not $directory) { continue }
        $config = Join-Path $directory 'bluestacks.conf'
        if (Test-Path -LiteralPath $config -PathType Leaf) {
            $key = '^bst\.instance\.' + [regex]::Escape($Instance) + '\.'
            if (-not (Select-String -LiteralPath $config -Pattern $key -Quiet)) {
                throw "$Instance is missing. Create a Nougat 32-bit instance in Multi-instance Manager. See README.txt."
            }
            break
        }
    }

    if ($Setup) {
        Start-Process -FilePath $player -ArgumentList @('--instance', $Instance)
        Start-Process -FilePath 'explorer.exe' -ArgumentList ('/select,"' + $apk + '"')
        Write-Host 'Drag the selected APK into the BlueStacks window. After installation, run Start-GD.cmd.'
    } else {
        Start-Process -FilePath $player -ArgumentList @(
            '--instance', $Instance, '--cmd', 'launchApp', '--package', 'com.robtopx.geometryjump'
        )
    }
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
