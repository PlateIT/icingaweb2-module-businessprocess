param([string]$Php = 'php.exe')

$ErrorActionPreference = 'Stop'
$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$testRoot = Join-Path $tempRoot ('icinga-bp-inventory-' + [guid]::NewGuid().ToString('N'))
$tokenFile = Join-Path $testRoot 'token'
$queryFile = Join-Path $testRoot 'query.json'
$router = Join-Path $PSScriptRoot 'kubernetes-inventory-api-router.php'
$server = $null
$previous = @{}
foreach ($name in @('BP_INVENTORY_QUERY_FILE','ICINGA_KUBERNETES_API_URL','ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE')) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = ([Net.IPEndPoint] $listener.LocalEndpoint).Port
$listener.Stop()
[IO.Directory]::CreateDirectory($testRoot) | Out-Null
[IO.File]::WriteAllText($tokenFile, 'inventory-test-token')
try {
    [Environment]::SetEnvironmentVariable('BP_INVENTORY_QUERY_FILE', $queryFile, 'Process')
    [Environment]::SetEnvironmentVariable('ICINGA_KUBERNETES_API_URL', "http://127.0.0.1:$port", 'Process')
    [Environment]::SetEnvironmentVariable('ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE', $tokenFile, 'Process')
    $server = Start-Process -FilePath $Php -ArgumentList @('-S', "127.0.0.1:$port", $router) `
        -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $testRoot 'server.out') `
        -RedirectStandardError (Join-Path $testRoot 'server.err')
    $deadline = (Get-Date).AddSeconds(10)
    do {
        Start-Sleep -Milliseconds 100
        try {
            $request = [Net.HttpWebRequest]::Create("http://127.0.0.1:$port/api/v1/status")
            $request.Proxy = $null
            $request.Headers['Authorization'] = 'Bearer inventory-test-token'
            $response = $request.GetResponse()
            $started = ([int] $response.StatusCode) -eq 200
            $response.Close()
        } catch {
            $started = $false
            if ($_.Exception.Response) { $_.Exception.Response.Close() }
        }
    } until ($started -or (Get-Date) -gt $deadline)
    if (-not $started) { throw 'Kubernetes inventory mock API did not start' }
    & $Php -d extension=curl (Join-Path $PSScriptRoot 'standalone-kubernetes-inventory-selection.php') $queryFile
    if ($LASTEXITCODE -ne 0) { throw 'Kubernetes inventory selection test failed' }
} finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    if ($server) { $server.WaitForExit(); $server.Dispose() }
    foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    $resolved = [IO.Path]::GetFullPath($testRoot)
    if (-not $resolved.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase)) { throw "unsafe test directory $resolved" }
    if ([IO.Directory]::Exists($resolved)) { [IO.Directory]::Delete($resolved, $true) }
}
