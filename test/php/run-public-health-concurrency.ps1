param([string]$Php = 'php.exe')

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http
$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$testRoot = Join-Path $tempRoot ('icinga-bp-health-' + [guid]::NewGuid().ToString('N'))
$outageFile = Join-Path $testRoot 'backend-unavailable'
$router = Join-Path $PSScriptRoot 'public-health-router.php'
$servers = @()
$previousOutage = [Environment]::GetEnvironmentVariable('BP_HEALTH_OUTAGE_FILE', 'Process')
$previousDelay = [Environment]::GetEnvironmentVariable('BP_HEALTH_DELAY_MS', 'Process')

function New-LoopbackPort {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { return ([Net.IPEndPoint] $listener.LocalEndpoint).Port } finally { $listener.Stop() }
}

function Invoke-HealthBatch([Net.Http.HttpClient]$Client, [int[]]$Ports, [int]$Count, [int]$ExpectedStatus, [string]$ExpectedState) {
    $tasks = [Collections.Generic.List[Threading.Tasks.Task[Net.Http.HttpResponseMessage]]]::new()
    for ($index = 0; $index -lt $Count; $index++) {
        $port = $Ports[$index % $Ports.Count]
        $tasks.Add($Client.GetAsync("http://127.0.0.1:$port/health"))
    }
    $completed = [Threading.Tasks.Task]::WaitAll([Threading.Tasks.Task[]] $tasks.ToArray(), 15000)
    if (-not $completed) { throw "parallel health batch did not finish within 15 seconds" }
    foreach ($task in $tasks) {
        $response = $task.Result
        $body = $response.Content.ReadAsStringAsync().Result
        if ([int] $response.StatusCode -ne $ExpectedStatus) { throw "health status $([int] $response.StatusCode), want $ExpectedStatus`: $body" }
        $payload = $body | ConvertFrom-Json
        if ($payload.status -ne $ExpectedState) { throw "health state $($payload.status), want $ExpectedState" }
        if ($body -match 'credential|endpoint|simulated|backend') { throw "backend details leaked: $body" }
        if ($response.Headers.CacheControl.ToString() -ne 'no-store') { throw 'public health response is cacheable' }
    }
}

[IO.Directory]::CreateDirectory($testRoot) | Out-Null
try {
    [Environment]::SetEnvironmentVariable('BP_HEALTH_OUTAGE_FILE', $outageFile, 'Process')
    [Environment]::SetEnvironmentVariable('BP_HEALTH_DELAY_MS', '20', 'Process')
    $ports = 1..3 | ForEach-Object { New-LoopbackPort }
    foreach ($port in $ports) {
        $stdout = Join-Path $testRoot "$port.out"
        $stderr = Join-Path $testRoot "$port.err"
        $servers += Start-Process -FilePath $Php -ArgumentList @('-S', "127.0.0.1:$port", $router) `
            -WorkingDirectory (Split-Path -Parent $router) -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput $stdout -RedirectStandardError $stderr
    }
    $httpHandler = [Net.Http.HttpClientHandler]::new()
    $httpHandler.UseProxy = $false
    $client = [Net.Http.HttpClient]::new($httpHandler)
    $client.Timeout = [TimeSpan]::FromSeconds(5)
    try {
        $deadline = (Get-Date).AddSeconds(10)
        foreach ($port in $ports) {
            do {
                try { $ready = $client.GetAsync("http://127.0.0.1:$port/health").Result.StatusCode -eq 200 } catch { $ready = $false }
                if (-not $ready) { Start-Sleep -Milliseconds 100 }
            } until ($ready -or (Get-Date) -gt $deadline)
            if (-not $ready) { throw "PHP health replica on port $port did not start" }
        }
        Invoke-HealthBatch $client $ports 60 200 'UP'
        [IO.File]::WriteAllText($outageFile, 'unavailable')
        Invoke-HealthBatch $client $ports 60 503 'UNKNOWN'
        [IO.File]::Delete($outageFile)
        Invoke-HealthBatch $client $ports 60 200 'UP'

        $restartTimer = [Diagnostics.Stopwatch]::StartNew()
        for ($index = 0; $index -lt $ports.Count; $index++) {
            $oldServer = $servers[$index]
            if (-not $oldServer.HasExited) { Stop-Process -Id $oldServer.Id -Force }
            $oldServer.WaitForExit()
            $oldServer.Dispose()
            $port = $ports[$index]
            $servers[$index] = Start-Process -FilePath $Php -ArgumentList @('-S', "127.0.0.1:$port", $router) `
                -WorkingDirectory (Split-Path -Parent $router) -WindowStyle Hidden -PassThru `
                -RedirectStandardOutput (Join-Path $testRoot "$port-restart.out") `
                -RedirectStandardError (Join-Path $testRoot "$port-restart.err")
            $deadline = (Get-Date).AddSeconds(10)
            do {
                try { $ready = $client.GetAsync("http://127.0.0.1:$port/health").Result.StatusCode -eq 200 } catch { $ready = $false }
                if (-not $ready) { Start-Sleep -Milliseconds 100 }
            } until ($ready -or $servers[$index].HasExited -or (Get-Date) -gt $deadline)
            if (-not $ready) { throw "restarted PHP health replica on port $port did not become ready" }
            Invoke-HealthBatch $client $ports 60 200 'UP'
        }
        $restartTimer.Stop()
        if ($restartTimer.Elapsed -ge [TimeSpan]::FromSeconds(30)) {
            throw "rolling restart under backlog required $($restartTimer.Elapsed.TotalSeconds) seconds"
        }
    } finally {
        $client.Dispose()
        $httpHandler.Dispose()
    }
    Write-Output "Three-replica Public Health outage and rolling-restart/backlog test passed in $([math]::Round($restartTimer.Elapsed.TotalSeconds, 2)) seconds."
} finally {
    foreach ($server in $servers) {
        if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force }
        $server.WaitForExit()
        $server.Dispose()
    }
    [Environment]::SetEnvironmentVariable('BP_HEALTH_OUTAGE_FILE', $previousOutage, 'Process')
    [Environment]::SetEnvironmentVariable('BP_HEALTH_DELAY_MS', $previousDelay, 'Process')
    $resolved = [IO.Path]::GetFullPath($testRoot)
    if (-not $resolved.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase)) { throw "unsafe test directory $resolved" }
    if ([IO.Directory]::Exists($resolved)) { [IO.Directory]::Delete($resolved, $true) }
}
