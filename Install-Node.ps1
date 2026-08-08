$ErrorActionPreference = 'Stop'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run Install-Node.bat as Administrator.'
    }
}

function Read-Required([string]$Prompt, [string]$Default = '') {
    $suffix = if ($Default) { " [$Default]" } else { '' }
    $value = Read-Host ($Prompt + $suffix)
    if (-not $value) { $value = $Default }
    if (-not $value) { throw "$Prompt cannot be empty." }
    return $value.Trim()
}

Assert-Administrator
$packageRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$installRoot = 'C:\ProgramData\AkaCloud'
$agentRoot = Join-Path $installRoot 'agent'
$templateRoot = Join-Path $installRoot 'template'
$runtimesRoot = Join-Path $installRoot 'runtimes'
$pythonExe = 'C:\Program Files\Python312\python.exe'
$qqExe = 'C:\Program Files\Tencent\QQNT\QQ.exe'
$qqVersion = 'C:\Program Files\Tencent\QQNT\versions\9.9.30-48517'

Write-Host 'AkaCloud QQ Node Installer' -ForegroundColor Cyan
Write-Host 'Every computer must use a unique node ID, for example xian-node-02.'
$defaultNodeId = (($env:COMPUTERNAME.ToLower() -replace '[^a-z0-9-]', '-') + '-qq')
$nodeId = Read-Required 'Unique node ID' $defaultNodeId
if ($nodeId -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$') {
    throw 'Node ID must be 3-64 letters, digits, dots, underscores or hyphens.'
}
if ($nodeId -eq 'xian-area-01') {
    throw 'xian-area-01 is already used. Enter a different node ID.'
}
$nodeName = Read-Required 'Node display name' $env:COMPUTERNAME

New-Item -ItemType Directory -Path $agentRoot,$templateRoot,$runtimesRoot -Force | Out-Null

if (-not (Test-Path -LiteralPath $pythonExe)) {
    $pythonInstaller = Join-Path $packageRoot 'Installers\python-3.12.10-amd64.exe'
    if (-not (Test-Path -LiteralPath $pythonInstaller)) { throw 'Python installer is missing.' }
    Write-Host 'Installing Python 3.12...'
    $process = Start-Process -FilePath $pythonInstaller -ArgumentList '/quiet InstallAllUsers=1 PrependPath=1 Include_test=0' -Wait -PassThru
    if ($process.ExitCode -ne 0 -or -not (Test-Path -LiteralPath $pythonExe)) {
        throw "Python installation failed. Exit code: $($process.ExitCode)"
    }
}

Write-Host 'Installing offline Python dependencies...'
& $pythonExe -m pip install --no-index --find-links (Join-Path $packageRoot 'Wheels') -r (Join-Path $packageRoot 'Program\requirements.txt')
if ($LASTEXITCODE -ne 0) { throw 'Python dependency installation failed.' }

if (-not (Test-Path -LiteralPath $qqExe) -or -not (Test-Path -LiteralPath $qqVersion)) {
    $qqInstaller = Join-Path $packageRoot 'Installers\QQ_9.9.30_x64.exe'
    if (-not (Test-Path -LiteralPath $qqInstaller)) { throw 'QQ installer is missing.' }
    Write-Host 'Installing QQ 9.9.30...'
    $process = Start-Process -FilePath $qqInstaller -ArgumentList '/S' -Wait -PassThru
    if (-not (Test-Path -LiteralPath $qqExe) -or -not (Test-Path -LiteralPath $qqVersion)) {
        Write-Host 'Complete the QQ installer window.' -ForegroundColor Yellow
        Start-Process -FilePath $qqInstaller -Wait
    }
    if (-not (Test-Path -LiteralPath $qqExe) -or -not (Test-Path -LiteralPath $qqVersion)) {
        throw 'Compatible QQ version 9.9.30-48517 was not found after installation.'
    }
}

Write-Host 'Copying Agent and NapCat template...'
Copy-Item -LiteralPath (Join-Path $packageRoot 'Program\agent.py') -Destination (Join-Path $agentRoot 'agent.py') -Force
Copy-Item -LiteralPath (Join-Path $packageRoot 'Program\requirements.txt') -Destination (Join-Path $agentRoot 'requirements.txt') -Force
Copy-Item -Path (Join-Path $packageRoot 'NapCat-Template\*') -Destination $templateRoot -Recurse -Force

$config = Get-Content -LiteralPath (Join-Path $packageRoot 'Program\agent_config.template.json') -Raw | ConvertFrom-Json
$config.node_id = $nodeId
$config.name = $nodeName
$config.accounts = @()
$configJson = $config | ConvertTo-Json -Depth 20
[IO.File]::WriteAllText((Join-Path $agentRoot 'agent_config.json'), $configJson, (New-Object Text.UTF8Encoding($false)))

& icacls $templateRoot /inheritance:r | Out-Null
& icacls $templateRoot /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
& icacls $agentRoot /inheritance:r | Out-Null
& icacls $agentRoot /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null

& (Join-Path $packageRoot 'Lock-QQ-Updates.ps1')

$launcher = @"
@echo off
cd /d "$agentRoot"
"$pythonExe" -u agent.py --config agent_config.json >> agent-service.log 2>&1
"@
Set-Content -LiteralPath (Join-Path $agentRoot 'Start-Node.cmd') -Value $launcher -Encoding ASCII

$taskName = 'AkaCloud-QQ-Node'
& schtasks.exe /Delete /TN $taskName /F 2>$null | Out-Null
& schtasks.exe /Create /TN $taskName /SC ONLOGON /RL HIGHEST /TR ('"' + (Join-Path $agentRoot 'Start-Node.cmd') + '"') /F | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Failed to create the startup task.' }

Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'python.exe' -and $_.CommandLine -like '*AkaCloud*agent.py*agent_config.json*'
} | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
Start-Process -FilePath $pythonExe -ArgumentList '-u','agent.py','--config','agent_config.json' -WorkingDirectory $agentRoot -WindowStyle Hidden
Start-Sleep -Seconds 8

$listening = Get-NetTCPConnection -LocalPort 3015 -State Listen -ErrorAction SilentlyContinue
if (-not $listening) { throw 'Agent did not listen on port 3015. Check agent-service.log.' }

Write-Host ''
Write-Host 'Installation completed.' -ForegroundColor Green
Write-Host "Node ID: $nodeId"
Write-Host "Node name: $nodeName"
Write-Host 'Concurrent account limit: 10'
Write-Host 'Idle release: 10 minutes; account profiles are retained.'
Write-Host 'QQ automatic updates: locked'
Read-Host 'Press Enter to exit'
