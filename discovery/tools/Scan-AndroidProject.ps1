#Requires -Version 5.1
<#
.SYNOPSIS
    STEP 1 of the Vizitor migration: full READ-ONLY inventory of the Android project.

.DESCRIPTION
    Scans an Android project and produces the evidence base required by the migration
    brief (section 1): Activities, Fragments, ViewModels, Repositories, DataSources,
    DAOs, Models/DTOs, API services, HTTP clients, Login/Session classes, Room queries,
    Retrofit endpoints, hardcoded URLs / connection strings, Gradle config, permissions.

    It NEVER modifies the project and NEVER prints a secret value (credentials are
    reported as file:line + masked text).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\Scan-AndroidProject.ps1 `
        -ProjectRoot "$env:USERPROFILE\Desktop\Vizitor-2.17.1-Luxury-AI-Final" `
        -OutDir "$env:USERPROFILE\Desktop\vizitor-discovery"
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string] $ProjectRoot,
    [string] $OutDir = (Join-Path ([Environment]::GetFolderPath('Desktop')) 'vizitor-discovery'),
    [string[]] $ExcludeDirs = @('build', '.gradle', '.idea', 'generated', 'intermediates', 'tmp', 'node_modules')
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $ProjectRoot)) { throw "ProjectRoot پیدا نشد: $ProjectRoot" }
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

Write-Host "Scanning: $ProjectRoot" -ForegroundColor Cyan
Write-Host "Output  : $OutDir" -ForegroundColor Cyan

function Should-Skip([string]$Path) {
    foreach ($d in $ExcludeDirs) {
        if ($Path -match "[\\/]$([regex]::Escape($d))[\\/]") { return $true }
    }
    return $false
}

function Mask-Secret([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return '' }
    if ($Value.Length -le 4) { return '****' }
    return ($Value.Substring(0, 2) + ('*' * [Math]::Min(10, $Value.Length - 2)) + $Value.Substring($Value.Length - 2))
}

function Read-TextSafe([string]$Path) {
    try { return [IO.File]::ReadAllText($Path) }
    catch { Write-Warning "Could not read $Path : $($_.Exception.Message)"; return '' }
}

# ----------------------------------------------------------------------------------
# 1. Collect source files
# ----------------------------------------------------------------------------------
$allFiles = Get-ChildItem -LiteralPath $ProjectRoot -Recurse -File -ErrorAction SilentlyContinue |
            Where-Object { -not (Should-Skip $_.FullName) }

$srcFiles   = $allFiles | Where-Object { $_.Extension -in '.kt', '.java' }
$gradleFiles= $allFiles | Where-Object { $_.Name -match '^(build\.gradle(\.kts)?|settings\.gradle(\.kts)?|gradle\.properties|libs\.versions\.toml)$' }
$manifests  = $allFiles | Where-Object { $_.Name -eq 'AndroidManifest.xml' }
$resXml     = $allFiles | Where-Object { $_.Extension -eq '.xml' -and $_.FullName -match '[\\/]res[\\/]values' }

Write-Host ("Found {0} source files, {1} gradle files, {2} manifests" -f $srcFiles.Count, $gradleFiles.Count, $manifests.Count) -ForegroundColor Yellow

# ----------------------------------------------------------------------------------
# 2. Classification patterns
# ----------------------------------------------------------------------------------
$categories = [ordered]@{
    'Activity'       = ':\s*[\w\.<>]*\b(AppCompatActivity|ComponentActivity|FragmentActivity|BaseActivity|Activity)\b|\bextends\s+\w*Activity\b'
    'Fragment'       = ':\s*[\w\.<>]*\b(Fragment|BottomSheetDialogFragment|DialogFragment|BaseFragment)\b|\bextends\s+\w*Fragment\b'
    'ViewModel'      = ':\s*[\w\.<>]*\b(ViewModel|AndroidViewModel|BaseViewModel)\b|\bextends\s+\w*ViewModel\b'
    'Repository'     = '(?i)\bclass\s+\w*Repository\b|\binterface\s+\w*Repository\b'
    'DataSource'     = '(?i)\b(class|interface|object)\s+\w*(DataSource|RemoteDataSource|LocalDataSource)\b'
    'Dao'            = '@Dao|\binterface\s+\w*Dao\b'
    'ApiService'     = '@(GET|POST|PUT|DELETE|PATCH|HEAD|Multipart|FormUrlEncoded)\b'
    'HttpClient'     = '(?i)\b(OkHttpClient|Retrofit|Volley|HttpURLConnection|RequestQueue|Interceptor)\b'
    'Entity'         = '@Entity\b'
    'Dto'            = '(?i)\b(class|data class)\s+\w*(Dto|DTO|Response|Request|Payload)\b'
    'Model'          = '(?i)\b(data class|class)\s+\w*(Model|Entity|Bean|Item)\b'
    'Login'          = '(?i)\b(class|object|interface)\s+\w*(Login|SignIn|Auth|Authenticate)\w*'
    'Session'        = '(?i)\b(class|object|interface)\s+\w*(Session|Token|UserPrefs|CurrentUser|LoginState)\w*'
    'Cache'          = '(?i)\b(class|object|interface)\s+\w*(Cache|MemoryCache|LruCache)\b|@Query\b'
    'ConnectionConfig'='(?i)(jdbc:|Data Source=|Initial Catalog=|Server=|ConnectionString|SQL_SERVER|DbConfig)'
    'DI'             = '@(Module|Provides|Singleton|InstallIn|HiltAndroidApp)\b|\bsingle\s*(<|\{)|\bfactory\s*(<|\{)'
    'Room'           = '@(Database|Dao|Entity|Query|Insert|Update|Delete|Transaction)\b'
    'Navigation'     = '(?i)(NavController|findNavController|NavHost|composable\(|<fragment\b)'
}

$classes = New-Object System.Collections.Generic.List[object]
$endpoints = New-Object System.Collections.Generic.List[object]
$sqlUsage = New-Object System.Collections.Generic.List[object]
$secrets = New-Object System.Collections.Generic.List[object]

foreach ($f in $srcFiles) {
    $text = Read-TextSafe $f.FullName
    if (-not $text) { continue }
    $rel = $f.FullName.Substring($ProjectRoot.Length).TrimStart('\', '/')
    $lines = $text -split "`r?`n"

    $hits = @()
    foreach ($k in $categories.Keys) {
        if ([regex]::IsMatch($text, $categories[$k])) { $hits += $k }
    }

    $classes.Add([pscustomobject]@{
        File       = $rel
        FileName   = $f.Name
        Lines      = $lines.Count
        Bytes      = $f.Length
        Categories = ($hits -join ';')
    })

    # ---- Retrofit endpoints (verb + path + enclosing function + params) ----
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = $lines[$i]
        $m = [regex]::Match($line, '@(GET|POST|PUT|DELETE|PATCH|HEAD)\s*\(\s*"([^"]*)"')
        if ($m.Success) {
            $funcName = ''
            $params = ''
            for ($j = $i; $j -lt [Math]::Min($i + 8, $lines.Count); $j++) {
                $fm = [regex]::Match($lines[$j], '(?:fun|suspend fun)\s+(\w+)\s*\(([^)]*)\)')
                if ($fm.Success) { $funcName = $fm.Groups[1].Value; $params = $fm.Groups[2].Value -replace '\s+', ' '; break }
            }
            $annotations = @()
            for ($j = $i + 1; $j -lt [Math]::Min($i + 12, $lines.Count); $j++) {
                $am = [regex]::Matches($lines[$j], '@(Query|Path|Body|Field|Header|Part)\s*\(\s*(?:value\s*=\s*)?"([^"]*)"')
                foreach ($a in $am) { $annotations += "$($a.Groups[1].Value):$($a.Groups[2].Value)" }
            }
            $endpoints.Add([pscustomobject]@{
                File = $rel; Line = ($i + 1); Verb = $m.Groups[1].Value; Path = $m.Groups[2].Value
                Function = $funcName; Params = $params; Annotations = ($annotations -join ',')
            })
        }

        # ---- Room / raw SQL inside the client ----
        $qm = [regex]::Match($line, '@Query\s*\(\s*"([^"]*)"')
        if ($qm.Success) {
            $sqlUsage.Add([pscustomobject]@{ File=$rel; Line=($i+1); Kind='Room @Query'; Sql=$qm.Groups[1].Value })
        }
        if ($line -match '(?i)\brawQuery\s*\(|\bexecSQL\s*\(|"(\s*(SELECT|INSERT|UPDATE|DELETE|EXEC)\b[^"]*)"') {
            $sm = [regex]::Match($line, '"(\s*(SELECT|INSERT|UPDATE|DELETE|EXEC)\b[^"]*)"')
            if ($sm.Success) { $sqlUsage.Add([pscustomobject]@{ File=$rel; Line=($i+1); Kind='inline SQL'; Sql=$sm.Groups[1].Value }) }
        }

        # ---- URLs / JDBC / connection strings / credentials ----
        foreach ($um in [regex]::Matches($line, '(https?://[^\s"''<>]+|jdbc:[^\s"''<>]+)')) {
            $secrets.Add([pscustomobject]@{ File=$rel; Line=($i+1); Kind='URL/JDBC'; Value=$um.Groups[1].Value })
        }
        foreach ($cm in [regex]::Matches($line, '(?i)(password|passwd|pwd|secret|api[_-]?key|token)\s*[=:]\s*"([^"]+)"')) {
            $secrets.Add([pscustomobject]@{
                File=$rel; Line=($i+1); Kind='HARDCODED-SECRET'
                Value=($cm.Groups[1].Value + '=' + (Mask-Secret $cm.Groups[2].Value))
            })
        }
        if ($line -match '(?i)(Data Source=|Initial Catalog=|User ID=|Server=|Integrated Security=)') {
            $secrets.Add([pscustomobject]@{ File=$rel; Line=($i+1); Kind='ConnectionString'; Value=($line.Trim()) })
        }
    }
}

# ----------------------------------------------------------------------------------
# 3. Gradle / build configuration
# ----------------------------------------------------------------------------------
$gradleInfo = New-Object System.Collections.Generic.List[object]
foreach ($g in $gradleFiles) {
    $text = Read-TextSafe $g.FullName
    if (-not $text) { continue }
    $rel = $g.FullName.Substring($ProjectRoot.Length).TrimStart('\', '/')

    foreach ($key in 'compileSdk', 'compileSdkVersion', 'minSdk', 'minSdkVersion', 'targetSdk', 'targetSdkVersion',
                     'applicationId', 'versionName', 'versionCode', 'namespace', 'jvmTarget', 'sourceCompatibility',
                     'multiDexEnabled', 'buildToolsVersion') {
        $m = [regex]::Match($text, "(?m)^\s*$key\s*[=]?\s*[`"']?([A-Za-z0-9\._\-]+)")
        if ($m.Success) { $gradleInfo.Add([pscustomobject]@{ File=$rel; Setting=$key; Value=$m.Groups[1].Value }) }
    }
    foreach ($dm in [regex]::Matches($text, '(?m)^\s*(implementation|api|compileOnly|runtimeOnly|kapt|ksp|annotationProcessor|testImplementation|androidTestImplementation)\s*\(?\s*["'']([^"'']+)["'']')) {
        $gradleInfo.Add([pscustomobject]@{ File=$rel; Setting="dep:$($dm.Groups[1].Value)"; Value=$dm.Groups[2].Value })
    }
    if ($text -match '(?m)^\s*signingConfigs\b') { $gradleInfo.Add([pscustomobject]@{ File=$rel; Setting='hasSigningConfig'; Value='yes' }) }
    if ($text -match 'proguard')                 { $gradleInfo.Add([pscustomobject]@{ File=$rel; Setting='proguardMentioned'; Value='yes' }) }
}

# ----------------------------------------------------------------------------------
# 4. Manifests
# ----------------------------------------------------------------------------------
$manifestInfo = New-Object System.Collections.Generic.List[object]
foreach ($mf in $manifests) {
    $text = Read-TextSafe $mf.FullName
    if (-not $text) { continue }
    $rel = $mf.FullName.Substring($ProjectRoot.Length).TrimStart('\', '/')

    foreach ($pm in [regex]::Matches($text, '<uses-permission[^>]*android:name="([^"]+)"')) {
        $manifestInfo.Add([pscustomobject]@{ File=$rel; Kind='permission'; Name=$pm.Groups[1].Value })
    }
    foreach ($am in [regex]::Matches($text, '<activity[^>]*android:name="([^"]+)"[^>]*(android:exported="([^"]+)")?')) {
        $manifestInfo.Add([pscustomobject]@{ File=$rel; Kind='activity'; Name=$am.Groups[1].Value })
    }
    foreach ($sm in [regex]::Matches($text, '<service[^>]*android:name="([^"]+)"')) {
        $manifestInfo.Add([pscustomobject]@{ File=$rel; Kind='service'; Name=$sm.Groups[1].Value })
    }
    foreach ($rm in [regex]::Matches($text, '<receiver[^>]*android:name="([^"]+)"')) {
        $manifestInfo.Add([pscustomobject]@{ File=$rel; Kind='receiver'; Name=$rm.Groups[1].Value })
    }
    if ($text -match 'android:usesCleartextTraffic="([^"]+)"') {
        $manifestInfo.Add([pscustomobject]@{ File=$rel; Kind='cleartextTraffic'; Name=$Matches[1] })
    }
    if ($text -match 'android:networkSecurityConfig="@xml/([^"]+)"') {
        $manifestInfo.Add([pscustomobject]@{ File=$rel; Kind='networkSecurityConfig'; Name=$Matches[1] })
    }
    if ($text -match 'android:minSdkVersion="([^"]+)"') {
        $manifestInfo.Add([pscustomobject]@{ File=$rel; Kind='manifestMinSdk'; Name=$Matches[1] })
    }
}

# ----------------------------------------------------------------------------------
# 5. Resource strings that look like endpoints / server config
# ----------------------------------------------------------------------------------
$resInfo = New-Object System.Collections.Generic.List[object]
foreach ($r in $resXml) {
    $text = Read-TextSafe $r.FullName
    if (-not $text) { continue }
    $rel = $r.FullName.Substring($ProjectRoot.Length).TrimStart('\', '/')
    foreach ($sm in [regex]::Matches($text, '<string\s+name="([^"]+)"[^>]*>([^<]*)</string>')) {
        $val = $sm.Groups[2].Value
        if ($val -match '(?i)(https?://|jdbc:|api|server|url|host|db|sql|password|token|1433)') {
            $shown = if ($sm.Groups[1].Value -match '(?i)(pass|secret|token|key)') { Mask-Secret $val } else { $val }
            $resInfo.Add([pscustomobject]@{ File=$rel; Name=$sm.Groups[1].Value; Value=$shown })
        }
    }
}

# ----------------------------------------------------------------------------------
# 6. Write outputs
# ----------------------------------------------------------------------------------
$classes   | Sort-Object File | Export-Csv -LiteralPath (Join-Path $OutDir 'android-classes.csv')   -NoTypeInformation -Encoding UTF8
$endpoints | Sort-Object File, Line | Export-Csv -LiteralPath (Join-Path $OutDir 'android-endpoints.csv') -NoTypeInformation -Encoding UTF8
$sqlUsage  | Sort-Object File, Line | Export-Csv -LiteralPath (Join-Path $OutDir 'android-sql-usage.csv') -NoTypeInformation -Encoding UTF8
$secrets   | Sort-Object File, Line | Export-Csv -LiteralPath (Join-Path $OutDir 'android-urls-secrets.csv') -NoTypeInformation -Encoding UTF8
$gradleInfo| Export-Csv -LiteralPath (Join-Path $OutDir 'android-gradle.csv') -NoTypeInformation -Encoding UTF8
$manifestInfo | Export-Csv -LiteralPath (Join-Path $OutDir 'android-manifest.csv') -NoTypeInformation -Encoding UTF8
$resInfo   | Export-Csv -LiteralPath (Join-Path $OutDir 'android-resource-strings.csv') -NoTypeInformation -Encoding UTF8

$md = New-Object System.Text.StringBuilder
[void]$md.AppendLine('# Android Inventory - Vizitor')
[void]$md.AppendLine('')
[void]$md.AppendLine("Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm')  ")
[void]$md.AppendLine("ProjectRoot: ``$ProjectRoot``  ")
[void]$md.AppendLine("Source files: $($srcFiles.Count) | Gradle files: $($gradleFiles.Count) | Manifests: $($manifests.Count)")
[void]$md.AppendLine('')
[void]$md.AppendLine('## Category summary')
[void]$md.AppendLine('| Category | Files |')
[void]$md.AppendLine('|---|---|')
foreach ($k in $categories.Keys) {
    $n = ($classes | Where-Object { $_.Categories -split ';' -contains $k }).Count
    [void]$md.AppendLine("| $k | $n |")
}
[void]$md.AppendLine('')
[void]$md.AppendLine('## Retrofit / HTTP endpoints found')
if ($endpoints.Count -eq 0) { [void]$md.AppendLine('_(none detected - the app may use a custom HTTP client; check android-urls-secrets.csv)_') }
else {
    [void]$md.AppendLine('| Verb | Path | Function | File:Line |')
    [void]$md.AppendLine('|---|---|---|---|')
    foreach ($e in ($endpoints | Sort-Object Path)) {
        [void]$md.AppendLine("| $($e.Verb) | ``$($e.Path)`` | $($e.Function) | $($e.File):$($e.Line) |")
    }
}
[void]$md.AppendLine('')
[void]$md.AppendLine('## Build configuration')
[void]$md.AppendLine('| File | Setting | Value |')
[void]$md.AppendLine('|---|---|---|')
foreach ($g in ($gradleInfo | Sort-Object File, Setting)) {
    [void]$md.AppendLine("| $($g.File) | $($g.Setting) | ``$($g.Value)`` |")
}
[void]$md.AppendLine('')
[void]$md.AppendLine('## Manifest (permissions / components)')
[void]$md.AppendLine('| Kind | Name |')
[void]$md.AppendLine('|---|---|')
foreach ($m in ($manifestInfo | Sort-Object Kind, Name)) { [void]$md.AppendLine("| $($m.Kind) | ``$($m.Name)`` |") }
[void]$md.AppendLine('')
[void]$md.AppendLine('## URLs / connection strings / potential hardcoded secrets')
[void]$md.AppendLine('> Secrets are masked on purpose. Check the file:line yourself.')
[void]$md.AppendLine('')
[void]$md.AppendLine('| Kind | File:Line | Value |')
[void]$md.AppendLine('|---|---|---|')
foreach ($s in ($secrets | Sort-Object Kind, File)) {
    $v = $s.Value
    if ($v.Length -gt 160) { $v = $v.Substring(0, 160) + '...' }
    [void]$md.AppendLine("| $($s.Kind) | $($s.File):$($s.Line) | ``$($v -replace '\|','\|')`` |")
}
[void]$md.AppendLine('')
[void]$md.AppendLine('## Full class/file inventory')
[void]$md.AppendLine('| File | Lines | Categories |')
[void]$md.AppendLine('|---|---|---|')
foreach ($c in ($classes | Sort-Object Categories, File)) { [void]$md.AppendLine("| $($c.File) | $($c.Lines) | $($c.Categories) |") }

$mdPath = Join-Path $OutDir 'android-inventory.md'
[IO.File]::WriteAllText($mdPath, $md.ToString(), (New-Object System.Text.UTF8Encoding($true)))

Write-Host ''
Write-Host '============================================' -ForegroundColor Green
Write-Host ' ANDROID INVENTORY DONE' -ForegroundColor Green
Write-Host '============================================' -ForegroundColor Green
Write-Host "  $mdPath" -ForegroundColor Cyan
Write-Host "  + 7 CSV files in $OutDir" -ForegroundColor Cyan
Write-Host ''
Write-Host ("  endpoints: {0} | sql usages: {1} | urls/secrets: {2}" -f $endpoints.Count, $sqlUsage.Count, $secrets.Count) -ForegroundColor Yellow
