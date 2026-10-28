param(
    [string]$Root = "$PSScriptRoot",
    [int]$Port    = 8080,
    [switch]$RealPhp
)

if ($RealPhp) {
    $phpBin = (Get-Command php -ErrorAction SilentlyContinue).Source
    if (-not $phpBin) {
        Write-Host "php.exe not found on PATH." -ForegroundColor Red
        Write-Host "Download PHP Windows zip from https://windows.php.net/download/"
        exit 1
    }
    Write-Host "Starting real PHP server via $phpBin" -ForegroundColor Cyan
    Write-Host "URL: http://localhost:$Port/" -ForegroundColor Green
    & $phpBin -S "localhost:$Port" -t $Root
    exit
}

$listener = [System.Net.HttpListener]::new()
$listener.Prefixes.Add("http://localhost:$Port/")
$listener.Start()

Write-Host ""
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  IPMI Web - DEMO MODE" -ForegroundColor Cyan
Write-Host "  URL:    http://localhost:$Port/" -ForegroundColor Green
Write-Host "  Root:   $Root" -ForegroundColor DarkGray
Write-Host "  Login:  admin / admin" -ForegroundColor Yellow
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""

$mime = @{
    '.html'='text/html; charset=utf-8'; '.php'='text/html; charset=utf-8';
    '.css'='text/css; charset=utf-8'; '.js'='application/javascript; charset=utf-8';
    '.json'='application/json; charset=utf-8'; '.svg'='image/svg+xml';
    '.png'='image/png'; '.jpg'='image/jpeg'; '.ico'='image/x-icon'
}

$bmcs = @(
    @{ id=1; name='Demo Server A'; host='192.168.1.100'; user='admin'; pass='admin'; interface='lanplus'; port=623; privl='ADMINISTRATOR' },
    @{ id=2; name='Demo Server B'; host='192.168.1.101'; user='admin'; pass='admin'; interface='lanplus'; port=623; privl='USER' }
)
$nextId = 3
$sessionLoggedIn = $false
$sessionUser = ''

function Send-Json($resp, $obj, $code) {
    if (-not $code) { $code = 200 }
    $resp.StatusCode = $code
    $resp.ContentType = 'application/json; charset=utf-8'
    $json = ($obj | ConvertTo-Json -Depth 10 -Compress)
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $resp.ContentLength64 = $bytes.Length
    $resp.OutputStream.Write($bytes, 0, $bytes.Length)
}

function Send-Text($resp, $text, $ctype, $code) {
    if (-not $ctype) { $ctype = 'text/html; charset=utf-8' }
    if (-not $code) { $code = 200 }
    $resp.StatusCode = $code
    $resp.ContentType = $ctype
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($text)
    $resp.ContentLength64 = $bytes.Length
    $resp.OutputStream.Write($bytes, 0, $bytes.Length)
}

function Read-Body($req) {
    $reader = New-Object System.IO.StreamReader($req.InputStream)
    return $reader.ReadToEnd()
}

function Parse-BodyJson($req) {
    $raw = Read-Body $req
    Write-Host "  [BODY RAW]: $raw" -ForegroundColor DarkGray
    if (-not $raw) { return @{} }
    try {
        $obj = $raw | ConvertFrom-Json
        $h = @{}
        $obj.PSObject.Properties | ForEach-Object { $h[$_.Name] = $_.Value }
        Write-Host "  [BODY PARSED]: keys=$($h.Keys -join ',') user=$($h['user'])" -ForegroundColor DarkGray
        return $h
    } catch {
        Write-Host "  [BODY PARSE ERR]: $_" -ForegroundColor Red
        return @{}
    }
}

function Get-QueryParams($query) {
    $h = @{}
    if (-not $query) { return $h }
    if ($query.StartsWith('?')) { $query = $query.Substring(1) }
    foreach ($p in $query.Split('&')) {
        if ($p.Contains('=')) {
            $kv = $p.Split('=', 2)
            $h[[System.Uri]::UnescapeDataString($kv[0])] = [System.Uri]::UnescapeDataString($kv[1])
        }
    }
    return $h
}

function Get-DemoOutput($cmd) {
    $c = $cmd.Trim().ToLower()
    if ($c.StartsWith('chassis status')) { return "System Power: on`nCooling/Fan Fault: false`nDrive Fault: false" }
    if ($c.StartsWith('chassis power status')) { return 'Chassis Power is on' }
    if ($c.StartsWith('chassis power on'))    { return 'Chassis Power Control: Up/On' }
    if ($c.StartsWith('chassis power off'))   { return 'Chassis Power Control: Down/Off' }
    if ($c.StartsWith('chassis power cycle')) { return 'Chassis Power Control: Cycle' }
    if ($c.StartsWith('chassis power reset'))  { return 'Chassis Power Control: Reset' }
    if ($c.StartsWith('sdr type temperature')) { return "Inlet Temp | ok | 24 C`nCPU Temp | ok | 48 C`nDIMM Temp | ok | 42 C" }
    if ($c.StartsWith('sdr type voltage'))    { return "CPU Vcore | ok | 1.20V`n3.3V | ok | 3.31V`n12V | ok | 12.05V" }
    if ($c.StartsWith('sdr type fan'))        { return "Fan1 | ok | 3200 RPM`nFan2 | ok | 3150 RPM`nFan3 | ok | 3300 RPM" }
    if ($c.StartsWith('sel'))                { return "1 | Power on`n2 | Watchdog`n3 | Temp critical`n4 | Fan fault" }
    if ($c.StartsWith('fru'))                { return "Manufacturer: Supermicro`nProduct: SYS-1029P-WTR`nSerial: SMR23A001234" }
    if ($c.StartsWith('raw'))                { return '00 01 02 03 04 05 06 07' }
    return "[DEMO] cmd=$c`n(No real ipmitool installed. Simulated output.)"
}

try {
    while ($listener.IsListening) {
        $ctx = $listener.GetContext()
        try {
            $req  = $ctx.Request
            $resp = $ctx.Response
            $uri  = $req.Url
            $path = [System.Uri]::UnescapeDataString($uri.AbsolutePath)
            $qs   = Get-QueryParams $uri.Query
            $body = @{}
            if ($req.HttpMethod -eq 'POST') { $body = Parse-BodyJson $req }

            Write-Host ("[{0}] {1} {2}" -f (Get-Date -Format 'HH:mm:ss'), $req.HttpMethod, $path)

            # Static files (non /api/ path) always go through first
            if (-not $path.StartsWith('/api')) {
                if ($path -eq '/') { $path = '/index.php' }
                if ($path.Contains('..')) { Send-Text $resp 'Forbidden' 'text/plain' 403; continue }
                $filePath = Join-Path $Root $path.TrimStart('/')
                if (-not (Test-Path $filePath -PathType Leaf)) {
                    Send-Text $resp "404 Not Found: $path" 'text/plain' 404
                    continue
                }
                $ext = [System.IO.Path]::GetExtension($filePath).ToLower()
                if ($mime.ContainsKey($ext)) { $resp.ContentType = $mime[$ext] }
                else { $resp.ContentType = 'application/octet-stream' }
                $resp.StatusCode = 200
                $bytes = [System.IO.File]::ReadAllBytes($filePath)
                $resp.ContentLength64 = $bytes.Length
                $resp.OutputStream.Write($bytes, 0, $bytes.Length)
                continue
            }

            # ==== Public API ====
            if ($path -eq '/api/ping') {
                Send-Json $resp @{ ok=$true; demo=$true }
                continue
            }
            if ($path -eq '/api/whoami') {
                $u = $null
                if ($sessionLoggedIn) { $u = $sessionUser }
                Send-Json $resp @{ ok=$true; logged=$sessionLoggedIn; user=$u }
                continue
            }
            if ($path -eq '/api/login') {
                $u = ''; $p = ''
                if ($body.ContainsKey('user')) { $u = $body['user'] }
                if ($body.ContainsKey('pass')) { $p = $body['pass'] }
                if ($u -eq 'admin' -and $p -eq 'admin') {
                    $script:sessionLoggedIn = $true
                    $script:sessionUser = $u
                    Send-Json $resp @{ ok=$true }
                } else {
                    Send-Json $resp @{ ok=$false; error='Invalid user or pass' } 401
                }
                continue
            }
            if ($path -eq '/api/logout') {
                $script:sessionLoggedIn = $false
                $script:sessionUser = ''
                Send-Json $resp @{ ok=$true }
                continue
            }

            if (-not $sessionLoggedIn) {
                Send-Json $resp @{ ok=$false; error='Not logged in' } 401
                continue
            }

            if ($path -eq '/api/bmcs_delete') {
                $id = 0
                if ($qs.ContainsKey('id')) { $id = [int]$qs['id'] }
                $new = @()
                foreach ($b in $bmcs) { if ($b.id -ne $id) { $new += $b } }
                $script:bmcs = $new
                Send-Json $resp @{ ok=$true }
                continue
            }
            if ($path -eq '/api/bmcs_update') {
                $id = 0
                if ($qs.ContainsKey('id')) { $id = [int]$qs['id'] }
                $found = $false
                for ($i = 0; $i -lt $bmcs.Count; $i++) {
                    if ($bmcs[$i].id -eq $id) {
                        foreach ($k in $body.Keys) {
                            if ($k -eq 'id') { continue }
                            if ($k -eq 'port') { $bmcs[$i].port = [int]$body[$k] }
                            else { $bmcs[$i].$k = $body[$k] }
                        }
                        $tmp = $bmcs[$i].PSObject.Copy()
                        $tmp.pass = $null
                        Send-Json $resp @{ ok=$true; data=$tmp }
                        $found = $true; break
                    }
                }
                if (-not $found) { Send-Json $resp @{ ok=$false; error='Not found' } 404 }
                continue
            }
            if ($path -eq '/api/bmcs') {
                if ($req.HttpMethod -eq 'GET') {
                    $out = @()
                    foreach ($b in $bmcs) {
                        $tmp = $b.PSObject.Copy(); $tmp.pass = $null; $out += $tmp
                    }
                    Send-Json $resp @{ ok=$true; data=$out }
                } else {
                    $port = 623; $privl = 'USER'; $iface = 'lanplus'
                    if ($body.ContainsKey('port') -and $body['port']) { $port = [int]$body['port'] }
                    if ($body.ContainsKey('privl') -and $body['privl']) { $privl = $body['privl'] }
                    if ($body.ContainsKey('interface') -and $body['interface']) { $iface = $body['interface'] }
                    $new = @{
                        id=$nextId; name=$body['name']; host=$body['host'];
                        user=$body['user']; pass=$body['pass'];
                        interface=$iface; port=$port; privl=$privl
                    }
                    $script:bmcs += $new
                    $script:nextId++
                    $tmp = $new.PSObject.Copy(); $tmp.pass = $null
                    Send-Json $resp @{ ok=$true; data=$tmp }
                }
                continue
            }
            if ($path -eq '/api/ipmi_run') {
                $id = 0; $cmd = ''
                if ($body.ContainsKey('id')) { $id = [int]$body['id'] }
                if ($body.ContainsKey('cmd')) { $cmd = [string]$body['cmd'] }
                $target = $null
                foreach ($b in $bmcs) { if ($b.id -eq $id) { $target = $b; break } }
                if (-not $target) { Send-Json $resp @{ ok=$false; error='BMC not found' } 404; continue }
                $stdout = Get-DemoOutput $cmd
                Send-Json $resp @{ ok=$true; data=@{ ok=$true; code=0; stdout=$stdout; stderr='' } }
                continue
            }
            if ($path -eq '/api/chassis_power') {
                $id = 0; $act = 'status'
                if ($qs.ContainsKey('id')) { $id = [int]$qs['id'] }
                if ($qs.ContainsKey('op')) { $act = $qs['op'] }
                if ($body.ContainsKey('id')) { $id = [int]$body['id'] }
                if ($body.ContainsKey('op')) { $act = $body['op'] }
                $target = $null
                foreach ($b in $bmcs) { if ($b.id -eq $id) { $target = $b; break } }
                if (-not $target) { Send-Json $resp @{ ok=$false; error='BMC not found' } 404; continue }
                if ($act -eq 'status') { $stdout = 'Chassis Power is on' }
                elseif ($act -eq 'on')    { $stdout = 'Chassis Power Control: Up/On' }
                elseif ($act -eq 'off')   { $stdout = 'Chassis Power Control: Down/Off' }
                elseif ($act -eq 'cycle') { $stdout = 'Chassis Power Control: Cycle' }
                elseif ($act -eq 'reset')  { $stdout = 'Chassis Power Control: Reset' }
                else                       { $stdout = 'Chassis Power Control: Soft Off' }
                Send-Json $resp @{ ok=$true; data=@{ ok=$true; code=0; stdout=$stdout; stderr='' } }
                continue
            }
            if ($path -eq '/api/dashboard') {
                $out = @()
                foreach ($b in $bmcs) {
                    $out += @{
                        id=$b.id; name=$b.name; host=$b.host;
                        ok=$true; stderr='';
                        status=@{ power='on'; cooling_fault=$false; drive_fault=$false }
                    }
                }
                Send-Json $resp @{ ok=$true; data=$out }
                continue
            }
            if ($path.StartsWith('/api/')) {
                Send-Json $resp @{ ok=$false; error="Unknown API: $path" } 404
                continue
            }
        } catch {
            Write-Host "ERR: $_" -ForegroundColor Red
        } finally {
            if ($ctx.Response) { try { $ctx.Response.Close() } catch {} }
        }
    }
} finally {
    $listener.Stop()
    Write-Host "Server stopped"
}
