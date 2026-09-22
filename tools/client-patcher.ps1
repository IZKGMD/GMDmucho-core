param(
    [string]$InputPath,
    [switch]$SelfTest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$DefaultServer = ''
$Latin1 = [System.Text.Encoding]::GetEncoding(28591)

function Get-UrlBytesLength {
    param([string]$Value)
    return $Latin1.GetByteCount($Value)
}

function Get-CompatibilityPath {
    param(
        [string]$Server,
        [int]$DesiredLength,
        [switch]$Bare
    )

    $uri = $null
    if (-not [Uri]::TryCreate($Server, [UriKind]::Absolute, [ref]$uri)) {
        if (-not [Uri]::TryCreate("https://$Server", [UriKind]::Absolute, [ref]$uri)) {
            throw 'Server address is not valid.'
        }
    }

    if ($uri.Scheme -notin @('http', 'https')) {
        throw 'Server address must use HTTP or HTTPS.'
    }

    if ($uri.PathAndQuery -ne '/' -and $uri.PathAndQuery -ne '') {
        throw 'Enter only the server address, for example https://gdps.example.com'
    }

    $hostPart = $uri.Host
    if ($uri.IsDefaultPort) {
        $portPart = ''
    } else {
        $portPart = ':' + $uri.Port
    }

    # Use only the scheme the user entered. Falling back to HTTPS for an
    # HTTP-only server creates a patched client that cannot connect.
    $schemes = if ($Bare) { @('bare') } else { @($uri.Scheme) }
    $segments = @('a', 'api', 'database', 'accounts')

    # Router::normalizePath strips these compatibility prefixes.
    # Search only the requested scheme.
    foreach ($scheme in $schemes) {
        $queue = New-Object System.Collections.Generic.List[object]
        $seen = New-Object System.Collections.Generic.HashSet[string]
        $queue.Add(@())

        while ($queue.Count -gt 0) {
            $current = $queue[0]
            $queue.RemoveAt(0)

            if ($Bare) {
                $prefix = if ($current.Count -eq 0) { '' } else { '/' + ($current -join '/') }
                $path = $prefix + '/database'
                $candidate = "$hostPart$portPart$path"
            } else {
                $path = if ($current.Count -eq 0) { '' } else { '/' + ($current -join '/') }
                $candidate = "$($scheme)://$hostPart$portPart$path"
            }

            if ((Get-UrlBytesLength $candidate) -eq $DesiredLength) {
                return $candidate
            }

            if ($current.Count -ge 6) {
                continue
            }

            foreach ($segment in $segments) {
                if ($Bare -and $segment -eq 'database') {
                    continue
                }

                $next = @($current + $segment)
                $key = $next -join '/'
                if ($seen.Add($key)) {
                    $queue.Add($next)
                }
            }
        }
    }

    throw "I could not build a compatible client URL with exactly $DesiredLength bytes. Use a shorter domain."
}

if ($SelfTest) {
    $testServer = 'https://gdps.example.com'
    $bareTestServer = 'https://school-gdps.com'
    foreach ($length in @(34, 33, 29, 28, 26)) {
        $value = Get-CompatibilityPath -Server $testServer -DesiredLength $length
        if ((Get-UrlBytesLength $value) -ne $length) {
            throw "Self-test failed for length $($length): $value"
        }
        Write-Host "PASS URL length $length -> $value"
    }

    $expected26 = Get-CompatibilityPath -Server $bareTestServer -DesiredLength 26 -Bare
    if (-not $expected26.EndsWith('/database')) {
        throw "Self-test produced an unexpected bare URL: $expected26"
    }

    $expected34 = Get-CompatibilityPath -Server $testServer -DesiredLength 34
    if ((Get-UrlBytesLength $expected34) -ne 34 -or -not $expected34.StartsWith('https://gdps.example.com/')) {
        throw "Self-test produced an unexpected 34-byte URL: $expected34"
    }

    $expected33 = Get-CompatibilityPath -Server $testServer -DesiredLength 33
    if (-not $expected33.StartsWith('https://')) {
        throw "Self-test selected a non-HTTPS scheme unexpectedly: $expected33"
    }

    $httpTestServer = 'http://gdps.example.com'
    $http29 = Get-CompatibilityPath -Server $httpTestServer -DesiredLength 29
    if (-not $http29.StartsWith('http://')) {
        throw "Self-test ignored the requested HTTP scheme: $http29"
    }

    $http25 = Get-CompatibilityPath -Server $httpTestServer -DesiredLength 25
    if (-not $http25.StartsWith('http://')) {
        throw "Self-test ignored the requested HTTP scheme: $http25"
    }

    Write-Host 'MUCHOCORE_CLIENT_PATCHER_OK'
    exit 0
}

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

function Get-Bytes {
    param([string]$Value)
    return $Latin1.GetBytes($Value)
}

function Replace-BinaryText {
    param(
        [System.IO.FileInfo]$File,
        [byte[]]$Data,
        [string]$OldText,
        [string]$NewText,
        [string]$Label
    )

    $oldBytes = Get-Bytes $OldText
    $newBytes = Get-Bytes $NewText

    if ($oldBytes.Length -ne $newBytes.Length) {
        return [pscustomobject]@{
            Data = $Data
            Count = 0
            Label = $Label
            Skipped = $true
            Reason = 'replacement length mismatch'
        }
    }

    $text = $Latin1.GetString($Data)
    $count = 0

    $index = 0
    while (($found = $text.IndexOf($OldText, $index, [StringComparison]::Ordinal)) -ge 0) {
        $count++
        $index = $found + $OldText.Length
    }

    if ($count -eq 0) {
        return [pscustomobject]@{
            Data = $Data
            Count = 0
            Label = $Label
            Skipped = $false
            Reason = ''
        }
    }

    $text = $text.Replace($OldText, $NewText)
    $newData = $Latin1.GetBytes($text)

    return [pscustomobject]@{
        Data = $newData
        Count = $count
        Label = $Label
        Skipped = $false
        Reason = ''
    }
}

function New-UniqueOutputPath {
    param([string]$InputFile)

    $item = Get-Item -LiteralPath $InputFile
    $base = Join-Path $item.DirectoryName ($item.BaseName + '-MuchoCore' + $item.Extension)

    if (-not (Test-Path -LiteralPath $base)) {
        return $base
    }

    $n = 2
    while ($true) {
        $candidate = Join-Path $item.DirectoryName ($item.BaseName + "-MuchoCore-$n" + $item.Extension)
        if (-not (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
        $n++
    }
}

function Invoke-Patch {
    param(
        [string]$InputFile,
        [string]$Server
    )

    if (-not (Test-Path -LiteralPath $InputFile -PathType Leaf)) {
        throw "Client file was not found: $InputFile"
    }

    $item = Get-Item -LiteralPath $InputFile
    if ($item.Extension -ne '.exe') {
        throw 'Choose the Geometry Dash .exe file.'
    }

    $serverUri = $null
    if (-not [Uri]::TryCreate($Server, [UriKind]::Absolute, [ref]$serverUri)) {
        if (-not [Uri]::TryCreate("https://$Server", [UriKind]::Absolute, [ref]$serverUri)) {
            throw 'Server address is not valid.'
        }
        $Server = $serverUri.AbsoluteUri.TrimEnd('/')
    } else {
        $Server = $serverUri.GetLeftPart([UriPartial]::Authority).TrimEnd('/')
    }

    if ($serverUri.PathAndQuery -ne '/' -and $serverUri.PathAndQuery -ne '') {
        throw 'Enter only the server address, for example https://gdps.example.com'
    }

    if ($serverUri.Scheme -notin @('http', 'https')) {
        throw 'Server address must use HTTP or HTTPS.'
    }

    $bytes = [System.IO.File]::ReadAllBytes($InputFile)
    $report = New-Object System.Collections.Generic.List[object]

    $urls = @{}
    foreach ($length in @(34, 33, 29, 28, 26)) {
        try {
            $urls[$length] = Get-CompatibilityPath -Server $Server -DesiredLength $length
        } catch {
        }
    }

    $patterns = @(
        @{ Old = 'https://www.boomlings.com/database'; Length = 34; Label = 'GD 2.2 HTTPS database URL' },
        @{ Old = 'http://www.boomlings.com/database';  Length = 33; Label = 'Legacy HTTP database URL' },
        @{ Old = 'https://www.boomlings.com/';         Length = 26; Label = 'GD HTTPS root URL' },
        @{ Old = 'http://www.boomlings.com/';          Length = 25; Label = 'Legacy HTTP root URL' },
        @{ Old = 'www.boomlings.com/database';         Length = 26; Label = 'Legacy bare database URL' }
    )

    foreach ($pattern in $patterns) {
        if (-not $urls.ContainsKey($pattern.Length)) {
            continue
        }

        if ($pattern.Old -eq 'www.boomlings.com/database') {
            $replacement = Get-CompatibilityPath -Server $Server -DesiredLength $pattern.Length -Bare
        } else {
            $replacement = $urls[$pattern.Length]
        }

        $result = Replace-BinaryText -File $item -Data $bytes -OldText $pattern.Old -NewText $replacement -Label $pattern.Label

        $bytes = $result.Data
        if ($result.Count -gt 0) {
            $report.Add([pscustomobject]@{
                Label = $result.Label
                Count = $result.Count
                Replacement = $replacement
            })
        }
    }

    $b64Patterns = @(
        @{ Old = 'http://www.boomlings.com/database'; Length = 33; Label = 'Base64 legacy database URL' },
        @{ Old = 'https://www.boomlings.com/database'; Length = 34; Label = 'Base64 HTTPS database URL' },
        @{ Old = 'http://www.boomlings.com/'; Length = 25; Label = 'Base64 legacy root URL' },
        @{ Old = 'https://www.boomlings.com/'; Length = 26; Label = 'Base64 HTTPS root URL' }
    )

    foreach ($pattern in $b64Patterns) {
        if (-not $urls.ContainsKey($pattern.Length)) {
            continue
        }

        $oldB64 = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pattern.Old))
        $newB64 = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($urls[$pattern.Length]))

        $result = Replace-BinaryText -File $item -Data $bytes -OldText $oldB64 -NewText $newB64 -Label $pattern.Label

        $bytes = $result.Data
        if ($result.Count -gt 0) {
            $report.Add([pscustomobject]@{
                Label = $result.Label
                Count = $result.Count
                Replacement = $urls[$pattern.Length]
            })
        }
    }

    if ($report.Count -eq 0) {
        throw @"
I could not find a supported Geometry Dash server URL in this client.

This usually means:
- the file is not the expected Windows Geometry Dash build;
- this client uses a URL format that the automatic patcher does not know yet;
- or the server URL is not compatible with this client size.

The original file was not changed.
"@
    }

    $outputFile = New-UniqueOutputPath -InputFile $InputFile
    [System.IO.File]::WriteAllBytes($outputFile, $bytes)

    return [pscustomobject]@{
        Output = $outputFile
        Report = $report
        OriginalSize = $item.Length
        OutputSize = (Get-Item -LiteralPath $outputFile).Length
        Server = $Server
    }
}

$form = New-Object System.Windows.Forms.Form
$form.Text = 'MuchoCore Client Patcher'
$form.StartPosition = 'CenterScreen'
$form.Size = New-Object System.Drawing.Size(680, 430)
$form.MinimumSize = New-Object System.Drawing.Size(680, 430)

$title = New-Object System.Windows.Forms.Label
$title.Text = 'Geometry Dash -> MuchoCore'
$title.Font = New-Object System.Drawing.Font('Segoe UI', 16, [System.Drawing.FontStyle]::Bold)
$title.AutoSize = $true
$title.Location = New-Object System.Drawing.Point(24, 20)
$form.Controls.Add($title)

$hint = New-Object System.Windows.Forms.Label
$hint.Text = 'Choose GeometryDash.exe and enter your GDPS server address. The original file is never overwritten.'
$hint.AutoSize = $true
$hint.Location = New-Object System.Drawing.Point(26, 58)
$form.Controls.Add($hint)

$serverLabel = New-Object System.Windows.Forms.Label
$serverLabel.Text = 'MuchoCore server (required):'
$serverLabel.AutoSize = $true
$serverLabel.Location = New-Object System.Drawing.Point(26, 95)
$form.Controls.Add($serverLabel)

$serverBox = New-Object System.Windows.Forms.TextBox
$serverBox.Text = $DefaultServer
$serverBox.Location = New-Object System.Drawing.Point(170, 91)
$serverBox.Size = New-Object System.Drawing.Size(460, 25)
$form.Controls.Add($serverBox)

$fileLabel = New-Object System.Windows.Forms.Label
$fileLabel.Text = 'GeometryDash.exe:'
$fileLabel.AutoSize = $true
$fileLabel.Location = New-Object System.Drawing.Point(26, 137)
$form.Controls.Add($fileLabel)

$fileBox = New-Object System.Windows.Forms.TextBox
$fileBox.Location = New-Object System.Drawing.Point(170, 133)
$fileBox.Size = New-Object System.Drawing.Size(355, 25)
$form.Controls.Add($fileBox)

if ($InputPath) {
    $fileBox.Text = $InputPath
}

$browse = New-Object System.Windows.Forms.Button
$browse.Text = 'Browse...'
$browse.Location = New-Object System.Drawing.Point(535, 131)
$browse.Size = New-Object System.Drawing.Size(95, 29)
$browse.Add_Click({
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = 'Choose GeometryDash.exe'
    $dialog.Filter = 'Geometry Dash executable (*.exe)|*.exe|All files (*.*)|*.*'
    if ($dialog.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
        $fileBox.Text = $dialog.FileName
    }
})
$form.Controls.Add($browse)

$status = New-Object System.Windows.Forms.TextBox
$status.Multiline = $true
$status.ReadOnly = $true
$status.ScrollBars = 'Vertical'
$status.Location = New-Object System.Drawing.Point(26, 180)
$status.Size = New-Object System.Drawing.Size(604, 160)
$status.Font = New-Object System.Drawing.Font('Consolas', 9)
$status.Text = @'
Ready.

1. Choose your original GeometryDash.exe.
2. Check the server address.
3. Click Patch client.

A new -MuchoCore.exe file will be created next to the original.
'@
$form.Controls.Add($status)

$patch = New-Object System.Windows.Forms.Button
$patch.Text = 'Patch client'
$patch.Font = New-Object System.Drawing.Font('Segoe UI', 10, [System.Drawing.FontStyle]::Bold)
$patch.Location = New-Object System.Drawing.Point(450, 350)
$patch.Size = New-Object System.Drawing.Size(180, 38)
$patch.Add_Click({
    try {
        $patch.Enabled = $false
        $browse.Enabled = $false
        $status.Text = 'Checking the client...'

        $result = Invoke-Patch -InputFile $fileBox.Text.Trim() -Server $serverBox.Text.Trim()

        $lines = New-Object System.Collections.Generic.List[string]
        $lines.Add('PATCH COMPLETE')
        $lines.Add('')
        $lines.Add("Server: $($result.Server)")
        $lines.Add("Output: $($result.Output)")
        $lines.Add("Original size: $($result.OriginalSize) bytes")
        $lines.Add("Output size:   $($result.OutputSize) bytes")
        $lines.Add('')
        $lines.Add('Replacements:')
        foreach ($entry in $result.Report) {
            $lines.Add(" - $($entry.Label): $($entry.Count)")
            $lines.Add("   -> $($entry.Replacement)")
        }
        $lines.Add('')
        $lines.Add('Start the new -MuchoCore.exe file.')
        $lines.Add('Your original GeometryDash.exe was not changed.')

        $status.Text = ($lines -join [Environment]::NewLine)

        [System.Windows.Forms.MessageBox]::Show(
            $form,
            ("Done!" + [Environment]::NewLine + [Environment]::NewLine + "A patched client was created next to the original file."),
            'MuchoCore Client Patcher',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Information
        ) | Out-Null
    } catch {
        $status.Text = "PATCH FAILED" + [Environment]::NewLine + [Environment]::NewLine + $_.Exception.Message
        [System.Windows.Forms.MessageBox]::Show(
            $form,
            $_.Exception.Message,
            'MuchoCore Client Patcher',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    } finally {
        $patch.Enabled = $true
        $browse.Enabled = $true
    }
})
$form.Controls.Add($patch)

$footer = New-Object System.Windows.Forms.Label
$footer.Text = 'Use a legally obtained Geometry Dash client. MuchoCore patches only the server address.'
$footer.AutoSize = $true
$footer.Location = New-Object System.Drawing.Point(26, 355)
$form.Controls.Add($footer)

[void]$form.ShowDialog()
