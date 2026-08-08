$ErrorActionPreference = 'Stop'
$versions = 'C:\Program Files\Tencent\QQNT\versions'
if (-not (Test-Path -LiteralPath $versions)) { throw 'QQ versions directory was not found.' }
$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls $versions /remove:d '*S-1-5-32-545' | Out-Null
& icacls $versions /remove:d $currentUser | Out-Null
Write-Host 'QQ update directory is unlocked.' -ForegroundColor Green
