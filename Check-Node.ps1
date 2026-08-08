$agentRoot = 'C:\ProgramData\AkaCloud\agent'
$configPath = Join-Path $agentRoot 'agent_config.json'
if (-not (Test-Path -LiteralPath $configPath)) {
    Write-Host 'Node is not installed.' -ForegroundColor Red
    exit 1
}
$config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
Write-Host "Node ID: $($config.node_id)"
Write-Host "Node name: $($config.name)"
Write-Host "Account profiles: $($config.accounts.Count)"
Write-Host "Active accounts: $(@($config.accounts | Where-Object { $_.runtime_status -ne 'idle_offline' -and $_.http_port -gt 0 }).Count)/$($config.max_active_accounts)"
Write-Host "Agent port 3015: $([bool](Get-NetTCPConnection -LocalPort 3015 -State Listen -ErrorAction SilentlyContinue))"
Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'python.exe' -and $_.CommandLine -like '*agent.py*agent_config.json*'
} | Select-Object ProcessId,CommandLine | Format-List
if (Test-Path -LiteralPath (Join-Path $agentRoot 'agent-service.log')) {
    Write-Host 'Recent log:'
    Get-Content -LiteralPath (Join-Path $agentRoot 'agent-service.log') -Tail 20
}
