#Requires -Version 5.1
<#
.SYNOPSIS
    STEP 1 of the Vizitor migration: READ-ONLY inventory of the PHP/IIS API layer.

.DESCRIPTION
    Builds the "Endpoint -> SQL -> Table/Procedure" map required by section 17 of the
    migration brief, straight from the source code (no guessing).

    Extracts:
      * every endpoint file (*.php, *.aspx, *.ashx, *.asmx, *.cs)
      * request parameters  ($_GET / $_POST / $_REQUEST / $request->input)
      * SQL statements and the tables / procedures / functions they touch
      * JSON response field names (=> the DTO contract the Android app expects)
      * authentication / session logic evidence (how passwords are verified)
      * where connection strings live (reported as file:line, value MASKED)

    It never modifies anything and never prints a credential.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\Scan-PhpApi.ps1 `
        -ApiRoot "C:\inetpub\wwwroot\vizitor-api" `
        -OutDir "$env:USERPROFILE\Desktop\vizitor-discovery"
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string] $ApiRoot,
    [string] $OutDir = (Join-Path ([Environment]::GetFolderPath('Desktop')) 'vizitor-discovery'),
    [string[]] $Extensions = @('.php', '.aspx', '.ashx', '.asmx', '.cs', '.inc', '.config', '.env')
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $ApiRoot)) { throw "ApiRoot پیدا نشد: $ApiRoot" }
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

Write-Host "Scanning API root: $ApiRoot" -ForegroundColor Cyan

function Mask-Secret([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return '' }
    if ($Value.Length -le 4) { return '****' }
    return ($Value.Substring(0, 3) + ('*' * [Math]::Min(12, $Value.Length - 3)) + $Value.Substring($Value.Length - 1))
}

function Read-TextSafe([string]$Path) {
    try { return [IO.File]::ReadAllText($Path) } catch { return '' }
}

$files = Get-ChildItem -LiteralPath $ApiRoot -Recurse -File -ErrorAction SilentlyContinue |
         Where-Object { $Extensions -contains $_.Extension.ToLower() -and $_.FullName -notmatch '[\\/](vendor|node_modules|\.git)[\\/]' }

Write-Host "Files to analyse: $($files.Count)" -ForegroundColor Yellow

$endpointRows = New-Object System.Collections.Generic.List[object]
$sqlRows      = New-Object System.Collections.Generic.List[object]
$objectRows   = New-Object System.Collections.Generic.List[object]
$fieldRows    = New-Object System.Collections.Generic.List[object]
$authRows     = New-Object System.Collections.Generic.List[object]
$connRows     = New-Object System.Collections.Generic.List[object]

$rxTable = [regex]'(?i)\b(?:FROM|JOIN|INSERT\s+INTO|UPDATE|DELETE\s+FROM|MERGE\s+INTO|TRUNCATE\s+TABLE)\s+\[?(?:dbo\s*\.\s*\[?)?([A-Za-z_][A-Za-z0-9_]*)\]?'
$rxProc  = [regex]'(?i)\bEXEC(?:UTE)?\s+\[?(?:dbo\s*\.\s*\[?)?([A-Za-z_][A-Za-z0-9_]*)\]?'
$rxFunc  = [regex]'(?i)\b((?:dbo\s*\.\s*)?(?:fn|uf|sf|vf)_[A-Za-z0-9_]+)\s*\('
$rxViewHint = [regex]'(?i)\bFROM\s+\[?(?:dbo\s*\.\s*\[?)?(v_|vw_|view_)([A-Za-z0-9_]*)'

foreach ($f in $files) {
    $text = Read-TextSafe $f.FullName
    if (-not $text) { continue }
    $rel = $f.FullName.Substring($ApiRoot.Length).TrimStart('\', '/')
    $lines = $text -split "`r?`n"

    # ---------- request parameters ----------
    $params = New-Object System.Collections.Generic.List[string]
    foreach ($m in [regex]::Matches($text, '\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[''"]([^''"]+)[''"]\s*\]')) {
        $params.Add("$($m.Groups[1].Value):$($m.Groups[2].Value)")
    }
    foreach ($m in [regex]::Matches($text, '(?:->\s*input\s*\(\s*|->\s*post\s*\(\s*|->\s*get\s*\(\s*|->\s*query\s*\(\s*)[''"]([^''"]+)[''"]')) {
        $params.Add("input:$($m.Groups[1].Value)")
    }
    foreach ($m in [regex]::Matches($text, '\$request\s*->\s*([A-Za-z_]\w*)')) { $params.Add("req->$($m.Groups[1].Value)") }

    # ---------- SQL statements (quoted strings that start with a SQL keyword) ----------
    $sqlTexts = New-Object System.Collections.Generic.List[string]
    foreach ($q in @('"([^"]{6,4000})"', "'([^']{6,4000})'")) {
        foreach ($m in [regex]::Matches($text, "(?is)$q")) {
            $s = $m.Groups[1].Value.Trim()
            if ($s -match '(?is)^\s*(SELECT|INSERT\s+INTO|INSERT|UPDATE|DELETE\s+FROM|DELETE|EXEC|EXECUTE|MERGE|WITH|DECLARE|BEGIN\s+TRAN)') {
                $sqlTexts.Add(($s -replace '\s+', ' '))
            }
        }
    }
    # Also catch SQL built by concatenation on a single line
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '(?i)(\$\s*(sql|query|cmd|stmt|strSql)\s*\.?=\s*.*(SELECT|INSERT|UPDATE|DELETE|EXEC)\b)') {
            $t = ($lines[$i].Trim() -replace '\s+', ' ')
            if ($t.Length -gt 400) { $t = $t.Substring(0, 400) + '...' }
            $sqlTexts.Add("LINE$($i+1)| $t")
        }
    }

    # ---------- DB driver calls ----------
    $drivers = @()
    foreach ($d in 'sqlsrv_query','sqlsrv_prepare','mssql_query','odbc_exec','->prepare(','->query(','->exec(','mysqli_query','new PDO','sqlsrv_connect','mssql_connect','SqlConnection','SqlCommand','ExecuteNonQuery','ExecuteReader') {
        if ($text.Contains($d)) { $drivers += $d }
    }

    # ---------- collect object names from the SQL we found ----------
    $tables = New-Object System.Collections.Generic.List[string]
    $procs  = New-Object System.Collections.Generic.List[string]
    $funcs  = New-Object System.Collections.Generic.List[string]
    foreach ($s in $sqlTexts) {
        foreach ($m in $rxTable.Matches($s)) { $tables.Add($m.Groups[1].Value) }
        foreach ($m in $rxProc.Matches($s))  { $procs.Add($m.Groups[1].Value) }
        foreach ($m in $rxFunc.Matches($s))  { $funcs.Add($m.Groups[1].Value) }
        foreach ($m in $rxViewHint.Matches($s)) { $tables.Add("v_$($m.Groups[2].Value)") }
    }
    # also scan the whole file for dbo.fn_ / EXEC usage that lives outside quoted SQL
    foreach ($m in $rxFunc.Matches($text)) { $funcs.Add($m.Groups[1].Value) }
    foreach ($m in $rxProc.Matches($text)) { $procs.Add($m.Groups[1].Value) }

    $tablesU = $tables | Sort-Object -Unique
    $procsU  = $procs  | Sort-Object -Unique
    $funcsU  = $funcs  | Sort-Object -Unique

    $endpointRows.Add([pscustomobject]@{
        File        = $rel
        Extension   = $f.Extension
        Params      = (($params | Sort-Object -Unique) -join ',')
        DbDrivers   = ($drivers -join ',')
        SqlCount    = $sqlTexts.Count
        Tables      = ($tablesU -join ',')
        Procedures  = ($procsU -join ',')
        Functions   = ($funcsU -join ',')
        HasJsonOut  = [bool]($text -match '(?i)(json_encode|Response\.Write|JsonResult|WriteAllText|header\s*\(\s*[''"]Content-Type:\s*application/json)')
        HasSession  = [bool]($text -match '(?i)(session_start|\$_SESSION|HttpContext\.Current\.Session)')
    })

    foreach ($t in $tablesU) { $objectRows.Add([pscustomobject]@{ File=$rel; Kind='TABLE'; Name=$t }) }
    foreach ($p in $procsU)  { $objectRows.Add([pscustomobject]@{ File=$rel; Kind='PROC';  Name=$p }) }
    foreach ($fn in $funcsU) { $objectRows.Add([pscustomobject]@{ File=$rel; Kind='FUNC';  Name=$fn }) }

    foreach ($s in ($sqlTexts | Select-Object -Unique)) {
        $shown = if ($s.Length -gt 1000) { $s.Substring(0, 1000) + '...' } else { $s }
        $lineNo = 0
        if ($s -match '^LINE(\d+)\|') { $lineNo = [int]$Matches[1] }
        $sqlRows.Add([pscustomobject]@{ File=$rel; Line=$lineNo; Sql=$shown })
    }

    # ---------- JSON response field names (the DTO contract) ----------
    if ($text -match '(?i)(json_encode|JsonResult|application/json)') {
        foreach ($m in [regex]::Matches($text, '[''"]([A-Za-z_]\w*)[''"]\s*=>')) {
            $fieldRows.Add([pscustomobject]@{ File=$rel; Field=$m.Groups[1].Value })
        }
        foreach ($m in [regex]::Matches($text, '(?i)''([A-Za-z_]\w*)''\s*:')) {
            $fieldRows.Add([pscustomobject]@{ File=$rel; Field=$m.Groups[1].Value })
        }
    }

    # ---------- AUTH / SESSION evidence (needed to keep Atiran login intact) ----------
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $L = $lines[$i]
        if ($L -match '(?i)(sys_users|password|passwd|pwd|md5\s*\(|sha1\s*\(|sha256|hash\s*\(|password_verify|password_hash|token|session|login|logout|role|visitor|permission|access)') {
            $t = ($L.Trim() -replace '\s+', ' ')
            if ($t.Length -gt 300) { $t = $t.Substring(0, 300) + '...' }
            if ($t -match '(?i)(password|passwd|pwd|secret|token)\s*(=>|=|:)\s*[''"]?([^\s''",;)]+)') {
                $t = $t -replace [regex]::Escape($Matches[3]), (Mask-Secret $Matches[3])
            }
            $authRows.Add([pscustomobject]@{ File=$rel; Line=($i + 1); Code=$t })
        }
    }

    # ---------- connection strings (masked) ----------
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $L = $lines[$i]
        if ($L -match '(?i)(Server=|Data Source=|Initial Catalog=|Database=|UID=|User ID=|PWD=|Password=|connectionString|DB_HOST|DB_USER|DB_PASS|sqlsrv_connect|mssql_connect)') {
            $t = ($L.Trim() -replace '\s+', ' ')
            if ($t.Length -gt 300) { $t = $t.Substring(0, 300) + '...' }
            # mask anything that looks like a value after Password/PWD/DB_PASS
            $t = [regex]::Replace($t, '(?i)((?:password|pwd|db_pass|pass)\s*[=:>]+\s*)(''[^'']*''|"[^"]*"|[^\s;,)]+)', {
                param($mm) $mm.Groups[1].Value + (Mask-Secret $mm.Groups[2].Value)
            })
            $connRows.Add([pscustomobject]@{ File=$rel; Line=($i + 1); Config=$t })
        }
    }
}

# ----------------------------------------------------------------------------------
# Outputs
# ----------------------------------------------------------------------------------
$endpointRows | Sort-Object File | Export-Csv -LiteralPath (Join-Path $OutDir 'api-endpoints.csv') -NoTypeInformation -Encoding UTF8
$sqlRows      | Sort-Object File, Line | Export-Csv -LiteralPath (Join-Path $OutDir 'api-sql-statements.csv') -NoTypeInformation -Encoding UTF8
$objectRows   | Sort-Object Name, Kind, File | Export-Csv -LiteralPath (Join-Path $OutDir 'api-objects-used.csv') -NoTypeInformation -Encoding UTF8
($fieldRows | Sort-Object File, Field -Unique) | Export-Csv -LiteralPath (Join-Path $OutDir 'api-response-fields.csv') -NoTypeInformation -Encoding UTF8
$authRows     | Sort-Object File, Line | Export-Csv -LiteralPath (Join-Path $OutDir 'api-auth-evidence.csv') -NoTypeInformation -Encoding UTF8
$connRows     | Sort-Object File, Line | Export-Csv -LiteralPath (Join-Path $OutDir 'api-connection-config.csv') -NoTypeInformation -Encoding UTF8

$md = New-Object System.Text.StringBuilder
[void]$md.AppendLine('# PHP/IIS API Inventory - Vizitor')
[void]$md.AppendLine('')
[void]$md.AppendLine("Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm')  ")
[void]$md.AppendLine("ApiRoot: ``$ApiRoot``  |  files analysed: $($files.Count)")
[void]$md.AppendLine('')
[void]$md.AppendLine('## Object usage summary (TABLE / PROC / FUNC)')
[void]$md.AppendLine('| Kind | Object | Used by |')
[void]$md.AppendLine('|---|---|---|')
foreach ($grp in ($objectRows | Group-Object Kind, Name | Sort-Object Name)) {
    $kind = ($grp.Group[0].Kind)
    $name = ($grp.Group[0].Name)
    $by = (($grp.Group | Select-Object -ExpandProperty File -Unique) -join ', ')
    [void]$md.AppendLine("| $kind | ``$name`` | $by |")
}
[void]$md.AppendLine('')
[void]$md.AppendLine('## Endpoint -> SQL map')
foreach ($e in ($endpointRows | Sort-Object File)) {
    [void]$md.AppendLine('')
    [void]$md.AppendLine("### ``$($e.File)``")
    [void]$md.AppendLine("- Params: $($e.Params)")
    [void]$md.AppendLine("- DB layer: $($e.DbDrivers)")
    [void]$md.AppendLine("- Tables: $($e.Tables)")
    [void]$md.AppendLine("- Procedures: $($e.Procedures)")
    [void]$md.AppendLine("- Functions: $($e.Functions)")
    [void]$md.AppendLine("- JSON output: $($e.HasJsonOut) | Session: $($e.HasSession) | SQL statements found: $($e.SqlCount)")
}
[void]$md.AppendLine('')
[void]$md.AppendLine('## Connection configuration locations (values masked)')
[void]$md.AppendLine('| File:Line | Config |')
[void]$md.AppendLine('|---|---|')
foreach ($c in ($connRows | Sort-Object File, Line)) {
    [void]$md.AppendLine("| $($c.File):$($c.Line) | ``$($c.Config -replace '\|','\|')`` |")
}
[void]$md.AppendLine('')
[void]$md.AppendLine('## Auth / session evidence (first 200 hits)')
[void]$md.AppendLine('| File:Line | Code |')
[void]$md.AppendLine('|---|---|')
foreach ($a in ($authRows | Sort-Object File, Line | Select-Object -First 200)) {
    [void]$md.AppendLine("| $($a.File):$($a.Line) | ``$($a.Code -replace '\|','\|')`` |")
}

$mdPath = Join-Path $OutDir 'api-inventory.md'
[IO.File]::WriteAllText($mdPath, $md.ToString(), (New-Object System.Text.UTF8Encoding($true)))

Write-Host ''
Write-Host '============================================' -ForegroundColor Green
Write-Host ' API INVENTORY DONE' -ForegroundColor Green
Write-Host '============================================' -ForegroundColor Green
Write-Host "  $mdPath" -ForegroundColor Cyan
Write-Host ("  endpoints: {0} | sql statements: {1} | db objects: {2} | response fields: {3}" -f `
    $endpointRows.Count, $sqlRows.Count, ($objectRows | Sort-Object Kind,Name -Unique).Count, ($fieldRows | Sort-Object File,Field -Unique).Count) -ForegroundColor Yellow
