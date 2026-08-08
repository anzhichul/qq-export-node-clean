$ErrorActionPreference = 'Stop'
$versions = 'C:\Program Files\Tencent\QQNT\versions'
if (-not (Test-Path -LiteralPath $versions)) { throw 'QQ versions directory was not found.' }
Get-Process -Name 'QQUpdate','QQUpdateCenter' -ErrorAction SilentlyContinue | Stop-Process -Force
Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object {
    $_.TaskName -match 'QQ.*(Update|Upgrade)|(Update|Upgrade).*QQ'
} | Disable-ScheduledTask -ErrorAction SilentlyContinue | Out-Null
Get-Service -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -match 'QQ.*(Update|Upgrade)|(Update|Upgrade).*QQ'
} | ForEach-Object {
    Stop-Service -Name $_.Name -Force -ErrorAction SilentlyContinue
    Set-Service -Name $_.Name -StartupType Disabled -ErrorAction SilentlyContinue
}
$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls $versions /deny '*S-1-5-32-545:(WD,AD,DC)' | Out-Null
& icacls $versions /deny ($currentUser + ':(WD,AD,DC)') | Out-Null
Write-Host 'QQ update directory is locked.' -ForegroundColor Green
