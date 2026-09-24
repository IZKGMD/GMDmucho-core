param(
    [string]$InputPath,
    [string]$ServerUrl = 'https://muchogdps.space',
    [switch]$SelfTest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Find-Python {
    $py = Get-Command py.exe -ErrorAction SilentlyContinue
    if ($py) {
        return @($py.Source, '-3')
    }

    $python = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($python) {
        return @($python.Source)
    }

    throw 'Python 3 was not found. Install Python 3 and make sure "py" or "python" is available in PATH.'
}

function Invoke-CanonicalPatcher {
    param(
        [string]$FilePath,
        [string]$Server,
        [switch]$SelfTestMode
    )

    $python = Find-Python
    $script = Join-Path $PSScriptRoot 'client-patch.py'

    if (-not (Test-Path -LiteralPath $script -PathType Leaf)) {
        throw "Canonical patcher was not found: $script"
    }

    $arguments = @()
    if ($python.Count -gt 1) {
        $arguments += $python[1]
    }
    $arguments += $script
    $arguments += '--server-url'
    $arguments += $Server
    if ($FilePath) {
        $arguments += '--input'
        $arguments += $FilePath
    }
    if ($SelfTestMode) {
        $arguments += '--self-test'
    }

    $output = & $python[0] @arguments 2>&1
    $exitCode = $LASTEXITCODE

    return [pscustomobject]@{
        ExitCode = $exitCode
        Output = ($output -join [Environment]::NewLine)
    }
}

if ($SelfTest) {
    $result = Invoke-CanonicalPatcher -Server $ServerUrl -SelfTestMode
    $result.Output | Write-Host
    exit $result.ExitCode
}

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$form = New-Object System.Windows.Forms.Form
$form.Text = 'MuchoCore Client Patcher'
$form.StartPosition = 'CenterScreen'
$form.Size = New-Object System.Drawing.Size(720, 470)
$form.MinimumSize = New-Object System.Drawing.Size(720, 470)

$title = New-Object System.Windows.Forms.Label
$title.Text = 'Geometry Dash -> MuchoCore'
$title.Font = New-Object System.Drawing.Font('Segoe UI', 16, [System.Drawing.FontStyle]::Bold)
$title.AutoSize = $true
$title.Location = New-Object System.Drawing.Point(24, 18)
$form.Controls.Add($title)

$hint = New-Object System.Windows.Forms.Label
$hint.Text = 'Uses tools/client-patch.py as the single canonical patch engine. The original EXE is never overwritten.'
$hint.AutoSize = $true
$hint.Location = New-Object System.Drawing.Point(26, 52)
$form.Controls.Add($hint)

$serverLabel = New-Object System.Windows.Forms.Label
$serverLabel.Text = 'Server URL:'
$serverLabel.AutoSize = $true
$serverLabel.Location = New-Object System.Drawing.Point(26, 92)
$form.Controls.Add($serverLabel)

$serverBox = New-Object System.Windows.Forms.TextBox
$serverBox.Text = $ServerUrl
$serverBox.Location = New-Object System.Drawing.Point(170, 88)
$serverBox.Size = New-Object System.Drawing.Size(500, 25)
$form.Controls.Add($serverBox)

$fileLabel = New-Object System.Windows.Forms.Label
$fileLabel.Text = 'GeometryDash.exe:'
$fileLabel.AutoSize = $true
$fileLabel.Location = New-Object System.Drawing.Point(26, 132)
$form.Controls.Add($fileLabel)

$fileBox = New-Object System.Windows.Forms.TextBox
$fileBox.Location = New-Object System.Drawing.Point(170, 128)
$fileBox.Size = New-Object System.Drawing.Size(400, 25)
if ($InputPath) {
    $fileBox.Text = $InputPath
}
$form.Controls.Add($fileBox)

$browse = New-Object System.Windows.Forms.Button
$browse.Text = 'Browse...'
$browse.Location = New-Object System.Drawing.Point(580, 126)
$browse.Size = New-Object System.Drawing.Size(90, 30)
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
$status.Location = New-Object System.Drawing.Point(26, 174)
$status.Size = New-Object System.Drawing.Size(644, 210)
$status.Font = New-Object System.Drawing.Font('Consolas', 9)
$status.Text = @'
Ready.

1. Choose the original GeometryDash.exe.
2. Confirm the server URL.
3. Click Patch client.

The canonical Python patcher validates the PE header, patches legacy/UTF-16/Base64 server URLs, validates the output, and writes a SHA-256 report.
'@
$form.Controls.Add($status)

$patch = New-Object System.Windows.Forms.Button
$patch.Text = 'Patch client'
$patch.Font = New-Object System.Drawing.Font('Segoe UI', 10, [System.Drawing.FontStyle]::Bold)
$patch.Location = New-Object System.Drawing.Point(500, 400)
$patch.Size = New-Object System.Drawing.Size(170, 38)
$patch.Add_Click({
    try {
        $patch.Enabled = $false
        $browse.Enabled = $false
        $status.Text = 'Running canonical patcher...'

        $result = Invoke-CanonicalPatcher -FilePath $fileBox.Text.Trim() -Server $serverBox.Text.Trim()
        $status.Text = $result.Output

        if ($result.ExitCode -ne 0) {
            throw "Patcher exited with code $($result.ExitCode)."
        }

        $msg = 'Client patched successfully.' + [Environment]::NewLine + [Environment]::NewLine + 'See the report path in the output above.'
        [System.Windows.Forms.MessageBox]::Show(
            $form,
            $msg,
            'MuchoCore Client Patcher',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Information
        ) | Out-Null
    } catch {
        $status.Text = 'PATCH FAILED' + [Environment]::NewLine + [Environment]::NewLine + $_.Exception.Message
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
$footer.Text = 'MuchoCore client patching tool'
$footer.AutoSize = $true
$footer.Location = New-Object System.Drawing.Point(26, 412)
$form.Controls.Add($footer)

[void]$form.ShowDialog()
