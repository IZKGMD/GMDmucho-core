# Opens only the authenticated MuchoCore admin patcher.
# No login tokens, FTP passwords, or APK files are sent by this helper.
$ErrorActionPreference = 'Stop'
try {
    $text = Read-Host 'Enter YOUR GDPS HTTPS origin (example: https://mygdps.example)'
    $parsed = $null
    if (-not [Uri]::TryCreate($text, [UriKind]::Absolute, [ref]$parsed)) {
        throw 'Invalid URL: enter the HTTPS domain only.'
    }
    if ($parsed.Scheme -ne 'https' -or
        -not [string]::IsNullOrEmpty($parsed.UserInfo) -or
        -not [string]::IsNullOrEmpty($parsed.Query) -or
        -not [string]::IsNullOrEmpty($parsed.Fragment) -or
        $parsed.AbsolutePath -ne '/') {
        throw 'Use HTTPS domain root only, without paths, credentials, query or fragment.'
    }
    $target = $parsed.GetLeftPart([System.UriPartial]::Authority) + '/admin/?page=clientpatcher'
    Write-Host ('Opening: ' + $target)
    Start-Process $target
    Write-Host 'Sign in to your own admin panel, then select the Android APK patcher.'
    Write-Host 'The PHP 7-only experimental backend on 5v.pl does not include the full admin.'
    exit 0
}
catch {
    [Console]::Error.WriteLine('ERROR: ' + $_.Exception.Message)
    exit 1
}
