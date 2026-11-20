# MuchoCore 5v.pl GitHub FTP Bridge — local Windows setup wizard.
# No hosting password is passed as a command-line argument, saved as plaintext,
# or written to GitHub issues. GitHub CLI encrypts repository secrets locally.
# Supports Windows PowerShell 5.1. Only configures a fixed, known repository.
param([switch]$SelfTest)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Repository = 'IZKGMD/GMDmucho-core'
$Environment = 'muchocore-5v-test'
$ControlIssue = 357
$OwnerLogin = 'IZKGMD'
$OwnerId = '112145870'
$BackupKeyPath = Join-Path $env:LOCALAPPDATA 'MuchoCore\5v-bridge-backup-key.dpapi'

function Find-GitHubCLI {
    $gh = Get-Command 'gh.exe' -ErrorAction SilentlyContinue
    if ($null -ne $gh) { return $gh.Source }
    $candidates = @(
        (Join-Path $env:ProgramFiles 'GitHub CLI\gh.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\GitHub CLI\gh.exe')
    )
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
    }
    $winget = Get-Command 'winget.exe' -ErrorAction SilentlyContinue
    if ($null -eq $winget) {
        throw 'GitHub CLI не найден. Установи GitHub CLI с https://cli.github.com/ и запусти мастер снова.'
    }
    Write-Host '[SETUP] Устанавливаю официальный GitHub CLI через winget...'
    & $winget.Source install --id GitHub.cli --exact --source winget --accept-package-agreements --accept-source-agreements
    if ($LASTEXITCODE -ne 0) { throw 'GitHub CLI не установился. Проверь winget и повтори запуск.' }
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
    }
    $gh = Get-Command 'gh.exe' -ErrorAction SilentlyContinue
    if ($null -ne $gh) { return $gh.Source }
    throw 'GitHub CLI установлен, но не найден. Перезапусти мастер.'
}

# The command text contains ONLY fixed action names and vetted repository IDs.
# Sensitive input is written to redirected stdin without an extra newline.
function Invoke-GhCommand {
    param(
        [Parameter(Mandatory=$true)][string]$Arguments,
        [AllowNull()][string]$Stdin = $null,
        [switch]$Secret
    )
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $script:GhExe
    $psi.Arguments = $Arguments
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    if ($null -ne $Stdin) {
        $psi.RedirectStandardInput = $true
        if ($psi.PSObject.Properties.Name -contains 'StandardInputEncoding') {
            $psi.StandardInputEncoding = New-Object System.Text.UTF8Encoding($false)
        }
    }
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $psi
    try {
        if (-not $process.Start()) { throw 'Не удалось запустить GitHub CLI.' }
        if ($null -ne $Stdin) {
            $process.StandardInput.Write($Stdin)
            $process.StandardInput.Close()
        }
        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        if ($process.ExitCode -ne 0) {
            # Do not print command output for secret-setting operations.
            if ($Secret) { throw 'GitHub отказался сохранить Secret. Проверь доступ владельца к репозиторию.' }
            throw ('GitHub CLI: ' + $stderr.Trim())
        }
        return $stdout.Trim()
    } finally {
        $process.Dispose()
    }
}

function Set-ProtectedSecret {
    param([string]$Name, [string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { throw ('Пустой Secret: ' + $Name) }
    [void](Invoke-GhCommand -Arguments ('secret set ' + $Name + ' --repo ' + $Repository +
        ' --env ' + $Environment) -Stdin $Value -Secret)
    Write-Host ('[OK] GitHub Secret: ' + $Name)
}

function Get-LocalBackupKey {
    Add-Type -AssemblyName 'System.Security'
    if (Test-Path -LiteralPath $BackupKeyPath -PathType Leaf) {
        $encrypted = [Convert]::FromBase64String([IO.File]::ReadAllText($BackupKeyPath))
        $decrypted = [Security.Cryptography.ProtectedData]::Unprotect(
            $encrypted, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        $saved = [Text.Encoding]::UTF8.GetString($decrypted)
        if ($saved.Length -lt 32) { throw 'Сохранённый ключ резервных копий некорректен.' }
        return $saved
    }
    $bytes = New-Object byte[] 48
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $key = [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+','-').Replace('/','_')
    $sealed = [Security.Cryptography.ProtectedData]::Protect(
        [Text.Encoding]::UTF8.GetBytes($key), $null,
        [Security.Cryptography.DataProtectionScope]::CurrentUser)
    [void](New-Item -ItemType Directory -Path (Split-Path $BackupKeyPath -Parent) -Force)
    [IO.File]::WriteAllText($BackupKeyPath, [Convert]::ToBase64String($sealed))
    return $key
}

function Request-Credentials {
    Add-Type -AssemblyName 'System.Windows.Forms'
    Add-Type -AssemblyName 'System.Drawing'
    [System.Windows.Forms.Application]::EnableVisualStyles()
    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'MuchoCore 5v.pl — настройка FTP-моста'
    $form.ClientSize = [Drawing.Size]::new(590, 390)
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false

    $intro = New-Object System.Windows.Forms.Label
    $intro.Text = 'Секреты будут переданы в GitHub Environment, а не в чат или Issue.'
    $intro.Location = [Drawing.Point]::new(20, 14)
    $intro.Size = [Drawing.Size]::new(552, 32)
    $form.Controls.Add($intro)

    function Add-Field {
        param([int]$Y,[string]$Title,[bool]$IsPassword = $false)
        $label = New-Object System.Windows.Forms.Label
        $label.Text = $Title
        $label.Location = [Drawing.Point]::new(20,$Y)
        $label.Size = [Drawing.Size]::new(195,25)
        $form.Controls.Add($label)
        $field = New-Object System.Windows.Forms.TextBox
        $field.Location = [Drawing.Point]::new(220,$Y)
        $field.Size = [Drawing.Size]::new(347,27)
        $field.UseSystemPasswordChar = $IsPassword
        $form.Controls.Add($field)
        return $field
    }

    $ftpName = Add-Field 61 'FTP логин 5v.pl'
    $ftpPassword = Add-Field 107 'FTP пароль' $true
    $ftpHost = Add-Field 153 'FTP хост (если отличается)'
    $autoOwner = New-Object System.Windows.Forms.CheckBox
    $autoOwner.Text = 'Разрешить и полную установку (потребуются данные админа)'
    $autoOwner.Location = [Drawing.Point]::new(20,197)
    $autoOwner.Size = [Drawing.Size]::new(549,30)
    $autoOwner.Checked = $false
    $form.Controls.Add($autoOwner)
    $ownerName = Add-Field 238 'Имя владельца админки'
    $ownerPass = Add-Field 283 'Пароль владельца админки' $true
    $ownerName.Enabled = $false
    $ownerPass.Enabled = $false
    $autoOwner.Add_CheckedChanged({
        $ownerName.Enabled = $autoOwner.Checked
        $ownerPass.Enabled = $autoOwner.Checked
    })
    $submit = New-Object System.Windows.Forms.Button
    $submit.Text = 'Подключить GitHub'
    $submit.Location = [Drawing.Point]::new(390,340)
    $submit.Size = [Drawing.Size]::new(177,35)
    $form.Controls.Add($submit)
    $cancel = New-Object System.Windows.Forms.Button
    $cancel.Text = 'Отмена'
    $cancel.Location = [Drawing.Point]::new(270,340)
    $cancel.Size = [Drawing.Size]::new(105,35)
    $cancel.DialogResult = [Windows.Forms.DialogResult]::Cancel
    $form.Controls.Add($cancel)
    $form.CancelButton = $cancel
    $form.AcceptButton = $submit
    $submit.Add_Click({
        if ([string]::IsNullOrWhiteSpace($ftpName.Text) -or
            [string]::IsNullOrWhiteSpace($ftpPassword.Text)) {
            [void][Windows.Forms.MessageBox]::Show('Введите FTP логин и пароль.', 'MuchoCore')
            return
        }
        if ($ftpHost.Text.Trim().Length -gt 0 -and
            $ftpHost.Text.Trim() -notmatch '^(?:5v\.pl|[a-z0-9][a-z0-9.-]*\.5v\.pl)$') {
            [void][Windows.Forms.MessageBox]::Show('FTP хост должен относиться к 5v.pl.', 'MuchoCore')
            return
        }
        if ($autoOwner.Checked -and
            ([string]::IsNullOrWhiteSpace($ownerName.Text) -or
             [string]::IsNullOrWhiteSpace($ownerPass.Text))) {
            [void][Windows.Forms.MessageBox]::Show('Укажи логин и пароль существующего владельца админки.', 'MuchoCore')
            return
        }
        $form.DialogResult = [Windows.Forms.DialogResult]::OK
        $form.Close()
    })
    $choice = $form.ShowDialog()
    if ($choice -ne [Windows.Forms.DialogResult]::OK) {
        $form.Dispose()
        throw 'Настройка отменена.'
    }
    $result = @{
        FtpUser = $ftpName.Text.Trim()
        FtpPassword = $ftpPassword.Text
        FtpHost = $ftpHost.Text.Trim().ToLowerInvariant()
        OwnerEnabled = $autoOwner.Checked
        OwnerUser = $ownerName.Text.Trim()
        OwnerPassword = $ownerPass.Text
    }
    $form.Dispose()
    return $result
}

try {
    if ($SelfTest) {
        Write-Host 'MUCHOCORE_5V_WIZARD_SELFTEST_OK'
        exit 0
    }

    Write-Host 'MuchoCore GitHub FTP Bridge — безопасная первичная настройка'
    $GhExe = Find-GitHubCLI
    try {
        $login = Invoke-GhCommand -Arguments 'api user --jq .login'
        $uid = Invoke-GhCommand -Arguments 'api user --jq .id'
    } catch {
        Write-Host '[LOGIN] Откроется GitHub. Подтверди вход через браузер.'
        & $GhExe auth login --hostname github.com --git-protocol https --web
        if ($LASTEXITCODE -ne 0) { throw 'Не удалось войти в GitHub.' }
        $login = Invoke-GhCommand -Arguments 'api user --jq .login'
        $uid = Invoke-GhCommand -Arguments 'api user --jq .id'
    }
    if ($login -ne $OwnerLogin -or $uid -ne $OwnerId) {
        throw ('Выполнен вход как ' + $login + ', а нужен владелец репозитория IZKGMD.')
    }
    Write-Host '[OK] GitHub: IZKGMD'

    $values = Request-Credentials
    Write-Host '[SETUP] Создаю приватное окружение GitHub с доступом только из main...'
    $envUrl = 'repos/' + $Repository + '/environments/' + $Environment
    $envPolicy = '{"deployment_branch_policy":{"protected_branches":false,"custom_branch_policies":true}}'
    [void](Invoke-GhCommand -Arguments ('api --method PUT ' + $envUrl + ' --input -') -Stdin $envPolicy)
    $existing = Invoke-GhCommand -Arguments ('api ' + $envUrl + '/deployment-branch-policies --jq .branch_policies[].name')
    if (@($existing -split "\r?\n") -notcontains 'main') {
        [void](Invoke-GhCommand -Arguments ('api --method POST ' + $envUrl +
             '/deployment-branch-policies -f name=main'))
    }
    $existing = Invoke-GhCommand -Arguments ('api ' + $envUrl + '/deployment-branch-policies --jq .branch_policies[].name')
    if (@($existing -split "\r?\n") -notcontains 'main') {
        throw 'GitHub не подтвердил ограничение окружения на main.'
    }
    Write-Host '[OK] Environment: muchocore-5v-test (main only)'

    $backupKey = Get-LocalBackupKey
    Set-ProtectedSecret -Name 'MUCHO_5V_BACKUP_KEY' -Value $backupKey
    Set-ProtectedSecret -Name 'MUCHO_5V_FTP_USER' -Value $values.FtpUser
    Set-ProtectedSecret -Name 'MUCHO_5V_FTP_PASSWORD' -Value $values.FtpPassword
    if ($values.FtpHost -ne '') {
        [void](Invoke-GhCommand -Arguments ('variable set MUCHO_5V_FTP_HOST --repo ' +
            $Repository + ' --env ' + $Environment) -Stdin $values.FtpHost)
        Write-Host '[OK] FTP host сохранён как GitHub Environment variable.'
    }
    if ($values.OwnerEnabled) {
        Set-ProtectedSecret -Name 'MUCHO_5V_OWNER_USER' -Value $values.OwnerUser
        Set-ProtectedSecret -Name 'MUCHO_5V_OWNER_PASSWORD' -Value $values.OwnerPassword
    }
    $values.FtpPassword = $null
    $values.OwnerPassword = $null
    $backupKey = $null

    Write-Host '[CHECK] Запускаю безопасную проверку архива, без FTP...'
    [void](Invoke-GhCommand -Arguments ('issue comment ' + $ControlIssue +
        ' --repo ' + $Repository + ' --body "/5v check"'))
    Write-Host ''
    Write-Host '[OK] Мост настроен! Проверка запущена из GitHub Issue #357.'
    Write-Host 'https://github.com/IZKGMD/GMDmucho-core/issues/357'
    Write-Host 'Восстановить ключ шифрования бэкапов можно только из этого аккаунта Windows.'
    Write-Host ('Защищённый файл ключа: ' + $BackupKeyPath)
    Write-Host 'Если захочешь менять компьютер, заранее перенеси ключ через менеджер паролей.'
    Write-Host 'FTP ФАЙЛЫ ЕЩЁ НЕ ЗАГРУЖЕНЫ: для загрузки потребуется отдельная команда /5v upload.'
} catch {
    Write-Host ''
    Write-Host ('[ОШИБКА] ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
