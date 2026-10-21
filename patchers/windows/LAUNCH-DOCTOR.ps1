param(
    [string]$GameFolder = '',
    [string]$ExePath = '',
    [string]$ServerUrl = 'https://linasgdpsprivatese.5v.pl',
    [switch]$Launch
)

# MuchoCore Windows client launch doctor. Read-only except its local report.
# Does not patch Steamworks, replace licensing files, or modify game files.
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$results = New-Object System.Collections.ArrayList

function Record-Check {
    param([string]$Name, [string]$Status, [string]$Detail)
    [void]$results.Add([pscustomobject]@{
        check = $Name
        status = $Status
        detail = $Detail
    })
    Write-Host ('[' + $Status + '] ' + $Name + ': ' + $Detail)
}

function Probe-Url {
    param([string]$Label, [string]$Url, [string]$Method = 'GET')
    try {
        $args = @{
            Uri = $Url
            Method = $Method
            UseBasicParsing = $true
            TimeoutSec = 12
        }
        if ($Method -eq 'POST') {
            $args.ContentType = 'application/x-www-form-urlencoded'
            $args.Body = 'gameVersion=22&binaryVersion=51&type=0&page=0'
        }
        $response = Invoke-WebRequest @args
        $data = [string]$response.Content
        $data = $data.Trim()
        if ($data.Length -gt 130) {
            $data = $data.Substring(0, 130) + '...'
        }
        $http = [int]$response.StatusCode
        if ($http -eq 200 -and ($data -eq '1' -or
            $data -eq '-1' -or $data -eq '-2' -or
            $data -match '^1:1:')) {
            Record-Check $Label 'OK' ('HTTP ' + $http + ' / ' + $data)
        } else {
            Record-Check $Label 'WARN' ('HTTP ' + $http + ' / ' + $data)
        }
    } catch {
        $code = 'network error'
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
            $code = [string][int]$_.Exception.Response.StatusCode
        }
        Record-Check $Label 'FAIL' ('HTTP ' + $code + '; ' + $_.Exception.Message)
    }
}

function Check-Pe {
    param([string]$Exe)
    $stream = [System.IO.File]::OpenRead($Exe)
    try {
        $reader = New-Object System.IO.BinaryReader($stream)
        if ($reader.ReadUInt16() -ne 0x5a4d) {
            throw 'Missing DOS MZ header'
        }
        $stream.Position = 0x3c
        $offset = [int64]$reader.ReadUInt32()
        if ($offset -lt 0x40 -or ($offset + 24) -gt $stream.Length) {
            throw 'Invalid PE offset'
        }
        $stream.Position = $offset
        if ($reader.ReadUInt32() -ne 0x4550) {
            throw 'Missing PE signature'
        }
        $machine = $reader.ReadUInt16()
        $type = if ($machine -eq 0x8664) { 'Windows x64' }
                elseif ($machine -eq 0x014c) { 'Windows x86' }
                else { 'machine 0x' + $machine.ToString('x4') }
        Record-Check 'Windows EXE headers' 'OK' $type
    } finally {
        $stream.Close()
    }
}

function Show-RecentCrashes {
    try {
        $events = @(Get-WinEvent -FilterHashtable @{
            LogName = 'Application'
            Id = 1000
            StartTime = (Get-Date).AddHours(-6)
        } -MaxEvents 70 -ErrorAction Stop |
            Where-Object {
                $_.Message -match '(?i)GeometryDash|MuchoCore|Geometry Dash'
            } | Select-Object -First 4)
        if ($events.Count -eq 0) {
            Record-Check 'Windows crash log' 'INFO' 'No matching Application Error 1000 in the last 6h'
        } else {
            foreach ($event in $events) {
                $message = ($event.Message -replace '\s+', ' ').Trim()
                if ($message.Length -gt 320) {
                    $message = $message.Substring(0, 320)
                }
                Record-Check 'Windows crash log' 'WARN' ($event.TimeCreated.ToString('s') + ' / ' + $message)
            }
        }
    } catch {
        Record-Check 'Windows crash log' 'INFO' 'Event Log unavailable'
    }
}

Write-Host ''
Write-Host '======================================================'
Write-Host ' MuchoCore / GD 2.209 - Windows Launch Doctor'
Write-Host '======================================================'
Write-Host 'This tool does not alter the game or bypass Steam DRM.'
Write-Host ''

try {
    if ([string]::IsNullOrWhiteSpace($ExePath) -and
        [string]::IsNullOrWhiteSpace($GameFolder)) {
        $selection = (Read-Host 'Paste full path to PATCHED EXE (preferred), original EXE, or game folder').Trim().Trim('"')
        if ($selection -match '(?i)[.]exe$') {
            $ExePath = $selection.Trim('"')
        } else {
            $GameFolder = $selection.Trim('"')
        }
    }

    if (-not [string]::IsNullOrWhiteSpace($ExePath)) {
        $exe = (Resolve-Path -LiteralPath $ExePath -ErrorAction Stop).ProviderPath
        if (-not (Test-Path -LiteralPath $exe -PathType Leaf) -or
            [System.IO.Path]::GetExtension($exe).ToLowerInvariant() -ne '.exe') {
            throw 'Choose an existing Windows EXE'
        }
        $folder = Split-Path -Path $exe -Parent
    } else {
        $folder = (Resolve-Path -LiteralPath $GameFolder -ErrorAction Stop).ProviderPath
        $exe = Join-Path $folder 'GeometryDash.exe'
        $alternates = @(Get-ChildItem -LiteralPath $folder -Filter '*MuchoCore*.exe' -File -ErrorAction SilentlyContinue |
            Select-Object -First 1)
        if ($alternates.Count -gt 0) {
            if (Test-Path -LiteralPath $exe -PathType Leaf) {
                $answer = Read-Host ('Found patched client ' + $alternates[0].Name +
                    '. Type ORIGINAL to inspect GeometryDash.exe instead (Enter = patched)')
                if ($answer -cne 'ORIGINAL') {
                    $exe = $alternates[0].FullName
                }
            } else {
                $exe = $alternates[0].FullName
            }
        } elseif (-not (Test-Path -LiteralPath $exe -PathType Leaf)) {
            throw ('No GeometryDash.exe or *MuchoCore*.exe in ' + $folder)
        }
    }
    Record-Check 'Client path' 'OK' $exe
    $info = Get-Item -LiteralPath $exe
    Record-Check 'Client file size' 'INFO' (([math]::Round($info.Length / 1MB, 2)).ToString() + ' MiB')
    Check-Pe $exe
    $hash = (Get-FileHash -LiteralPath $exe -Algorithm SHA256).Hash.ToLowerInvariant()
    Record-Check 'Executable SHA-256' 'INFO' $hash

    switch ($hash) {
        '524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8' {
            Record-Check 'GD 2.209 build' 'WARN' 'Original 13 Oct build, NOT patched to 5v.pl'
        }
        '4eee06d5c2e2213b4ba2b1f6233dd426bbdd0e9b29d7c375e499516b6f8f69d5' {
            Record-Check 'GD 2.209 build' 'WARN' 'Original 19 Oct build, NOT patched to 5v.pl'
        }
        default {
            Record-Check 'GD 2.209 build' 'INFO' 'Unknown hash; may be patched or a different build'
        }
    }

    if ($info.Length -gt 30MB) {
        Record-Check 'URL inspection' 'INFO' 'Skipped for large EXE'
    } else {
        $binary = [System.IO.File]::ReadAllBytes($exe)
        $ascii = [System.Text.Encoding]::ASCII.GetString($binary)
        $destination = ([Uri]$ServerUrl).Host
        if ($ascii.Contains($destination)) {
            Record-Check 'Target server in EXE' 'OK' $destination
        } else {
            Record-Check 'Target server in EXE' 'WARN' ('No plain-text reference to ' + $destination)
        }
        if ($ascii.Contains('steam_api64.dll') -or $ascii.Contains('SteamAPI_Init')) {
            Record-Check 'Steam API references' 'WARN' 'Steamworks references detected; URL patching does NOT remove licensing requirements'
        } else {
            Record-Check 'Steam API references' 'INFO' 'Not detected by simple static string check; no guarantee of standalone support'
        }
        Remove-Variable binary,ascii -ErrorAction SilentlyContinue
    }

    foreach ($name in @('libcocos2d.dll','steam_api64.dll','fmod.dll','fmodstudio.dll')) {
        $path = Join-Path $folder $name
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            Record-Check ('Dependency ' + $name) 'OK' 'Present next to game'
        } else {
            Record-Check ('Dependency ' + $name) 'INFO' 'Not next to EXE (may be optional)'
        }
    }
    if (Test-Path -LiteralPath (Join-Path $folder 'geode')) {
        Record-Check 'Geode modifications' 'WARN' 'Geode folder found. Test a clean copy of the game separately'
    } else {
        Record-Check 'Geode modifications' 'OK' 'No geode/ folder beside EXE'
    }

    $server = [Uri]$ServerUrl
    if ($server.Scheme -ne 'https' -or
        $server.AbsolutePath -ne '/' -or
        -not [string]::IsNullOrEmpty($server.UserInfo) -or
        -not [string]::IsNullOrEmpty($server.Query) -or
        -not [string]::IsNullOrEmpty($server.Fragment)) {
        throw 'ServerUrl must be the HTTPS origin only; no /mc or credentials'
    }
    $root = $server.GetLeftPart([System.UriPartial]::Authority)
    Probe-Url '5v.pl PHP health' ($root + '/mc/health')
    Probe-Url 'GD 2.209 online check (root)' ($root + '/checkIfServerOnline.php') 'POST'
    Probe-Url 'GD 2.209 level search (/a/)' ($root + '/a/getGJLevels21.php') 'POST'
    Probe-Url 'GD 2.209 nested accounts rewrite' ($root + '/a/accounts/loginGJAccount.php') 'POST'

    if ($Launch) {
        $answer = Read-Host 'Launch THIS EXE for a 10-second observation? (YES/no)'
        if ($answer -ceq 'YES') {
            try {
                $process = Start-Process -FilePath $exe -WorkingDirectory $folder -PassThru -ErrorAction Stop
                Start-Sleep -Seconds 10
                $process.Refresh()
                if ($process.HasExited) {
                    Record-Check 'Windows game launch' 'FAIL' ('Process exited after launch; code=' + $process.ExitCode)
                } else {
                    Record-Check 'Windows game launch' 'OK' 'Process is still running after 10s; opening a usable game window is not yet verified'
                }
            } catch {
                Record-Check 'Windows game launch' 'FAIL' $_.Exception.Message
            }
        }
    }

    Show-RecentCrashes
} catch {
    Record-Check 'Diagnosis' 'FAIL' $_.Exception.Message
} finally {
    $saveDir = Join-Path $env:USERPROFILE 'Desktop'
    if (-not (Test-Path -LiteralPath $saveDir -PathType Container)) {
        $saveDir = $env:TEMP
    }
    $report = Join-Path $saveDir ('MuchoCore-launch-doctor-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')
    $out = [pscustomobject]@{
        created_at = (Get-Date).ToString('s')
        checks = @($results.ToArray())
        comment = 'No game files, DRM or Windows settings modified. Contains local paths and crash summaries; redact before publishing.'
    }
    $out | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $report -Encoding UTF8
    Write-Host ''
    Write-Host ('REPORT: ' + $report)
    Write-Host 'This report contains local paths and crash text. Do not post it publicly without reviewing.'
}
