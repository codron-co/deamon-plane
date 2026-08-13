<#
.SYNOPSIS
  Dalga 0 Coolify Public API v4 spike (Deamon Plane).
.DESCRIPTION
  Read-only by default. -Mutate only against COOLIFY_STAGING_APP_UUID.
  Never logs the API token. Does not create a Laravel app.
#>
[CmdletBinding()]
param(
    [switch]$Mutate
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$envFile = Join-Path $here '.env'

function Read-DotEnv {
    param([string]$Path)
    $map = @{}
    if (-not (Test-Path $Path)) {
        throw "Missing $Path — copy .env.example to .env and fill COOLIFY_BASE_URL + COOLIFY_API_TOKEN."
    }
    Get-Content $Path | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { return }
        $idx = $line.IndexOf('=')
        if ($idx -lt 1) { return }
        $key = $line.Substring(0, $idx).Trim()
        $val = $line.Substring($idx + 1).Trim().Trim('"').Trim("'")
        $map[$key] = $val
    }
    return $map
}

function Redact {
    param($obj)
    $json = $obj | ConvertTo-Json -Depth 8 -Compress
    if ($script:token -and $script:token.Length -gt 8) {
        $json = $json.Replace($script:token, '***REDACTED***')
    }
    return $json
}

function Invoke-Coolify {
    param(
        [string]$Method,
        [string]$Path,
        [object]$Body = $null
    )
    $uri = "$script:base$Path"
    $headers = @{
        Authorization = "Bearer $script:token"
        Accept        = 'application/json'
    }
    $params = @{
        Method      = $Method
        Uri         = $uri
        Headers     = $headers
        TimeoutSec  = 60
    }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json'
        $params.Body = ($Body | ConvertTo-Json -Depth 8 -Compress)
    }
    try {
        $resp = Invoke-RestMethod @params
        return @{ ok = $true; status = 200; data = $resp }
    } catch {
        $code = $null
        $text = $_.Exception.Message
        if ($_.Exception.Response) {
            $code = [int]$_.Exception.Response.StatusCode
        }
        return @{ ok = $false; status = $code; error = $text }
    }
}

function Summarize-Apps {
    param($apps)
    $list = @()
    if ($apps -is [System.Array]) { $list = $apps }
    elseif ($null -ne $apps) { $list = @($apps) }
    $rows = foreach ($a in $list) {
        [pscustomobject]@{
            uuid       = $a.uuid
            name       = $a.name
            git_branch = $a.git_branch
            build_pack = $a.build_pack
            fqdn       = $a.fqdn
        }
    }
    return $rows
}

$cfg = Read-DotEnv $envFile
$script:base = ($cfg['COOLIFY_BASE_URL'] -replace '/$', '')
if ($script:base -notmatch '/api/v1$') {
    $script:base = "$script:base/api/v1"
}
$script:token = $cfg['COOLIFY_API_TOKEN']
$stagingUuid = $cfg['COOLIFY_STAGING_APP_UUID']

if ([string]::IsNullOrWhiteSpace($script:base) -or [string]::IsNullOrWhiteSpace($script:token)) {
    throw 'COOLIFY_BASE_URL and COOLIFY_API_TOKEN are required.'
}

Write-Host "Coolify spike  base=$script:base  mutate=$Mutate"
Write-Host ''

$results = [ordered]@{}

Write-Host '== GET /applications'
$r = Invoke-Coolify GET '/applications'
$results.listApps = @{ ok = $r.ok; status = $r.status }
if ($r.ok) {
    $rows = Summarize-Apps $r.data
    $results.listApps.count = @($rows).Count
    $results.listApps.sample_fields = @('uuid', 'name', 'git_branch', 'build_pack', 'fqdn')
    $rows | Select-Object -First 8 | Format-Table -AutoSize | Out-String | Write-Host
} else {
    Write-Host "FAIL status=$($r.status) $($r.error)"
}

Write-Host '== GET /servers'
$r = Invoke-Coolify GET '/servers'
$results.listServers = @{ ok = $r.ok; status = $r.status }
if ($r.ok) {
    $servers = @($r.data)
    $results.listServers.count = $servers.Count
    $servers | ForEach-Object { [pscustomobject]@{ uuid = $_.uuid; name = $_.name } } |
        Format-Table -AutoSize | Out-String | Write-Host
} else {
    Write-Host "FAIL status=$($r.status) $($r.error)"
}

Write-Host '== GET /projects'
$r = Invoke-Coolify GET '/projects'
$results.listProjects = @{ ok = $r.ok; status = $r.status }
if ($r.ok) {
    $projects = @($r.data)
    $results.listProjects.count = $projects.Count
    $projects | ForEach-Object { [pscustomobject]@{ uuid = $_.uuid; name = $_.name } } |
        Format-Table -AutoSize | Out-String | Write-Host
} else {
    Write-Host "FAIL status=$($r.status) $($r.error)"
}

if ([string]::IsNullOrWhiteSpace($stagingUuid)) {
    Write-Host 'SKIP get staging app — set COOLIFY_STAGING_APP_UUID'
    $results.getApp = @{ ok = $false; skipped = $true }
} else {
    Write-Host "== GET /applications/$stagingUuid"
    $r = Invoke-Coolify GET "/applications/$stagingUuid"
    $results.getApp = @{ ok = $r.ok; status = $r.status }
    if ($r.ok) {
        $app = $r.data
        $results.getApp.shape = [ordered]@{
            uuid                    = $app.uuid
            name                    = $app.name
            git_branch              = $app.git_branch
            git_repository          = $app.git_repository
            build_pack              = $app.build_pack
            docker_compose_location = $app.docker_compose_location
            fqdn                    = $app.fqdn
        }
        $results.getApp.shape | ConvertTo-Json | Write-Host

        Write-Host "== GET /applications/$stagingUuid/storages"
        $s = Invoke-Coolify GET "/applications/$stagingUuid/storages"
        $results.listStorages = @{ ok = $s.ok; status = $s.status }
        if ($s.ok) {
            $volNames = @()
            if ($s.data.persistent_storages) {
                $volNames = @($s.data.persistent_storages | ForEach-Object { $_.name })
            }
            $results.listStorages.volume_names = $volNames
            Write-Host ("volumes: " + ($volNames -join ', '))
        } else {
            Write-Host "FAIL storages status=$($s.status) $($s.error)"
        }

        Write-Host "== GET /applications/$stagingUuid/envs (keys only)"
        $e = Invoke-Coolify GET "/applications/$stagingUuid/envs"
        $results.listEnvs = @{ ok = $e.ok; status = $e.status }
        if ($e.ok) {
            $keys = @($e.data | ForEach-Object { $_.key })
            $results.listEnvs.keys = $keys
            Write-Host ("env keys: " + ($keys -join ', '))
        }

        Write-Host "== GET /deployments/applications/$stagingUuid"
        $d = Invoke-Coolify GET "/deployments/applications/${stagingUuid}?take=5"
        $results.listDeployments = @{ ok = $d.ok; status = $d.status }
        if ($d.ok) {
            Write-Host (Redact $d.data)
        }
    } else {
        Write-Host "FAIL status=$($r.status) $($r.error)"
    }
}

if ($Mutate) {
    if ([string]::IsNullOrWhiteSpace($stagingUuid)) {
        throw '-Mutate requires COOLIFY_STAGING_APP_UUID'
    }
    $target = $cfg['SPIKE_TARGET_BRANCH']
    if ([string]::IsNullOrWhiteSpace($target)) { $target = 'alpha' }

    Write-Host "== MUTATE PATCH git_branch=$target"
    $p = Invoke-Coolify PATCH "/applications/$stagingUuid" @{ git_branch = $target }
    $results.updateBranch = @{ ok = $p.ok; status = $p.status }
    if (-not $p.ok) { Write-Host "FAIL updateBranch $($p.error)" }

    Write-Host "== MUTATE POST /deploy?uuid=$stagingUuid"
    $dep = Invoke-Coolify POST "/deploy?uuid=$stagingUuid"
    $results.deploy = @{ ok = $dep.ok; status = $dep.status }
    if ($dep.ok) {
        Write-Host (Redact $dep.data)
        $depUuid = $null
        if ($dep.data.deployments) {
            $depUuid = @($dep.data.deployments)[0].deployment_uuid
        }
        $results.deploy.deployment_uuid = $depUuid
        if ($depUuid) {
            Write-Host "== GET /deployments/$depUuid"
            $g = Invoke-Coolify GET "/deployments/$depUuid"
            $results.getDeployment = @{ ok = $g.ok; status = $g.status }
            if ($g.ok) {
                $results.getDeployment.status_field = $g.data.status
                Write-Host ("deployment status: " + $g.data.status)
            }
        }
    } else {
        Write-Host "FAIL deploy $($dep.error)"
    }

    Write-Host '== MUTATE domain conflict probe (force_domain_override=false, no domain change if fqdn already set)'
    $results.setDomains = @{ ok = $false; skipped = $true; note = 'Set SPIKE_PROBE_DOMAIN to exercise PATCH docker_compose_domains' }
    $probe = $cfg['SPIKE_PROBE_DOMAIN']
    if (-not [string]::IsNullOrWhiteSpace($probe)) {
        $body = @{
            docker_compose_domains = @(
                @{ name = 'app'; domain = $probe }
            )
            force_domain_override = $false
        }
        $dom = Invoke-Coolify PATCH "/applications/$stagingUuid" $body
        $results.setDomains = @{ ok = $dom.ok; status = $dom.status }
        if (-not $dom.ok) { Write-Host "domain PATCH: $($dom.error)" }
        else { Write-Host (Redact $dom.data) }
    }
} else {
    Write-Host 'Read-only mode. Re-run with -Mutate against staging only to test branch+deploy+domain.'
}

$outPath = Join-Path $here 'last-run.json'
$results.generated_at = (Get-Date).ToString('o')
$results.mutate = [bool]$Mutate
$results | ConvertTo-Json -Depth 8 | Set-Content -Path $outPath -Encoding utf8
Write-Host ''
Write-Host "Wrote $outPath (no token)."
Write-Host 'Paste a redacted summary into docs/plans/2026-08-13-coolify-spike-notes.md Live proof section.'
