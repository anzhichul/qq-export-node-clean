$agentRoot = 'C:\ProgramData\AkaCloud\agent'
$pythonExe = 'C:\Program Files\Python312\python.exe'
Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'python.exe' -and $_.CommandLine -like '*agent.py*agent_config.json*'
} | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
Start-Sleep -Seconds 2
Start-Process -FilePath $pythonExe -ArgumentList '-u','agent.py','--config','agent_config.json' -WorkingDirectory $agentRoot -WindowStyle Hidden
Start-Sleep -Seconds 5
Write-Host "Agent port 3015: $([bool](Get-NetTCPConnection -LocalPort 3015 -State Listen -ErrorAction SilentlyContinue))"
