#Requires -Version 5.1
<#
.SYNOPSIS
    Fix Java (JDK 17) + Build Vizitor 2.17.1 -> release APK on Desktop.

.DESCRIPTION
    1) Looks for a real JDK 17+ (JAVA_HOME, registry, PATH, Program Files, .jdks).
    2) If missing, installs it with winget using an EXPLICIT --source (fixes the
       "msstore / 0x8a15003b / Please specify one of them using the --source option"
       error), with extra vendor fallbacks and a direct Adoptium MSI download.
    3) Refreshes PATH/JAVA_HOME **inside the current session** from the registry,
       so you do NOT have to close and reopen PowerShell.
    4) Extracts the zip robustly (nested folder / long path / tar fallback).
    5) Checks the Gradle wrapper + Android SDK, builds, copies the APK to Desktop.

.NOTES
    Save this file as UTF-8 **with BOM** so Windows PowerShell 5.1 renders the
    Persian text correctly. Run from an elevated PowerShell for auto-install.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\build-vizitor.ps1
#>
[CmdletBinding()]
param(
    [string]   $ProjectName    = 'Vizitor-2.17.1-Luxury-AI-Final',
    [string]   $ZipName        = 'Vizitor-2.17.1-Luxury-AI-Final.zip',
    [string]   $OutputApkName  = 'Vizitor-2.17.1-Final.apk',
    [string]   $GradleTask     = 'assembleRelease',
    [int]      $JavaMajor      = 17,
    [string[]] $ExtraGradleArgs = @(),
    [switch]   $PersistEnv,          # also write JAVA_HOME/PATH permanently (User scope)
    [switch]   $SkipJavaInstall,     # only detect, never install
    [switch]   $AllowMissingSdk,     # build even if no Android SDK is found
    [switch]   $Pause                # wait for ENTER at the end (for right-click -> Run)
)

$ErrorActionPreference = 'Stop'
$script:ExitCode = 0

# ------------------------------------------------------------------------------
# Helpers
# ------------------------------------------------------------------------------

function Write-Step {
    param([string]$Message, [string]$Color = 'Yellow')
    Write-Host ''
    Write-Host "=== $Message ===" -ForegroundColor $Color
}

function Write-Ok   { param([string]$m) Write-Host $m -ForegroundColor Green }
function Write-Info { param([string]$m) Write-Host $m -ForegroundColor Cyan }
function Write-Warn { param([string]$m) Write-Host $m -ForegroundColor Magenta }

# Native commands that write to stderr (java -version, where.exe, winget) can
# raise a fake "NativeCommandError" when $ErrorActionPreference = 'Stop' on
# Windows PowerShell 5.1. This wrapper neutralises that.
function Invoke-StderrSafe {
    param([Parameter(Mandatory)][scriptblock]$Script)
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $result = & $Script
        return ($result | ForEach-Object { "$_" })
    }
    finally { $ErrorActionPreference = $old }
}

function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $pr = New-Object Security.Principal.WindowsPrincipal($id)
    return $pr.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# Re-read Machine + User environment from the registry into THIS process.
function Update-SessionEnvironment {
    $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $userPath    = [Environment]::GetEnvironmentVariable('Path', 'User')

    $parts = @()
    if ($machinePath) { $parts += ($machinePath -split ';' | Where-Object { $_ }) }
    if ($userPath)    { $parts += ($userPath    -split ';' | Where-Object { $_ }) }
    if ($parts.Count -gt 0) { $env:Path = (($parts | Select-Object -Unique) -join ';') }

    foreach ($name in 'JAVA_HOME','ANDROID_HOME','ANDROID_SDK_ROOT') {
        $v = [Environment]::GetEnvironmentVariable($name, 'Machine')
        if (-not $v) { $v = [Environment]::GetEnvironmentVariable($name, 'User') }
        if ($v) { Set-Item -Path "Env:$name" -Value $v }
    }
}

# ------------------------------------------------------------------------------
# Java detection
# ------------------------------------------------------------------------------

function Get-JavaMajorVersion {
    param([Parameter(Mandatory)][string]$JavaExe)
    try {
        $out = (Invoke-StderrSafe { & $JavaExe -version 2>&1 }) -join "`n"
    }
    catch { return 0 }

    if ($out -match 'version "(\d+)(?:\.(\d+))?') {
        $major = [int]$Matches[1]
        if ($major -eq 1 -and $Matches[2]) { return [int]$Matches[2] }  # 1.8.0_x -> 8
        return $major
    }
    return 0
}

function Get-JavaCandidatePaths {
    $candidates = New-Object System.Collections.Generic.List[string]

    # 1) JAVA_HOME (session / machine / user)
    $homes = @(
        $env:JAVA_HOME,
        [Environment]::GetEnvironmentVariable('JAVA_HOME', 'Machine'),
        [Environment]::GetEnvironmentVariable('JAVA_HOME', 'User')
    )
    foreach ($h in $homes) {
        if ($h) { $candidates.Add((Join-Path $h 'bin\java.exe')) }
    }

    # 2) Registry (JavaSoft keys written by Oracle / some vendors)
    $regRoots = @(
        'HKLM:\SOFTWARE\JavaSoft\JDK',
        'HKLM:\SOFTWARE\JavaSoft\Java Development Kit',
        'HKLM:\SOFTWARE\WOW6432Node\JavaSoft\JDK',
        'HKLM:\SOFTWARE\WOW6432Node\JavaSoft\Java Development Kit'
    )
    foreach ($root in $regRoots) {
        if (Test-Path $root) {
            Get-ChildItem $root -ErrorAction SilentlyContinue | ForEach-Object {
                $jh = (Get-ItemProperty -Path $_.PSPath -ErrorAction SilentlyContinue).JavaHome
                if ($jh) { $candidates.Add((Join-Path $jh 'bin\java.exe')) }
            }
        }
    }

    # 3) PATH
    try {
        Get-Command java -All -CommandType Application -ErrorAction SilentlyContinue |
            ForEach-Object { if ($_.Source) { $candidates.Add($_.Source) } }
    }
    catch { }

    # 4) Well-known install roots (this is what your old script was missing)
    $pf  = $env:ProgramFiles
    if (-not $pf)  { $pf  = 'C:\Program Files' }
    $pf8 = ${env:ProgramFiles(x86)}
    if (-not $pf8) { $pf8 = 'C:\Program Files (x86)' }
    $roots = @(
        (Join-Path $pf 'Eclipse Adoptium'),
        (Join-Path $pf 'AdoptOpenJDK'),
        (Join-Path $pf 'Java'),
        (Join-Path $pf 'Microsoft'),
        (Join-Path $pf 'Amazon Corretto'),
        (Join-Path $pf 'Zulu'),
        (Join-Path $pf 'BellSoft'),
        (Join-Path $pf 'Semeru'),
        (Join-Path $pf8 'Java'),
        (Join-Path $env:LOCALAPPDATA 'Programs\Eclipse Adoptium'),
        (Join-Path $env:USERPROFILE '.jdks'),
        'C:\Program Files\Android\Android Studio\jbr',
        'C:\Program Files\Android\Android Studio\jre'
    )
    foreach ($root in $roots) {
        if (-not $root -or -not (Test-Path $root)) { continue }

        # <root>\bin\java.exe  (e.g. Android Studio jbr)
        $direct = Join-Path $root 'bin\java.exe'
        if (Test-Path $direct) { $candidates.Add($direct) }

        # <root>\<jdk-17.x>\bin\java.exe
        Get-ChildItem -Path $root -Directory -ErrorAction SilentlyContinue | ForEach-Object {
            $nested = Join-Path $_.FullName 'bin\java.exe'
            if (Test-Path $nested) { $candidates.Add($nested) }
        }
    }

    return ($candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -Unique)
}

function Find-BestJava {
    param([int]$WantedMajor = 17)

    $found = @()
    foreach ($exe in (Get-JavaCandidatePaths)) {
        $ver   = Get-JavaMajorVersion -JavaExe $exe
        $isJdk = Test-Path (Join-Path (Split-Path $exe -Parent) 'javac.exe')
        $found += [pscustomobject]@{
            Exe     = $exe
            Home    = (Split-Path (Split-Path $exe -Parent) -Parent)
            Version = $ver
            IsJdk   = $isJdk
        }
    }
    if ($found.Count -eq 0) { return $null }

    # Rank: exact major + real JDK first, then newer JDKs, then anything else.
    $ranked = $found | Sort-Object `
        @{ Expression = { if ($_.Version -eq $WantedMajor) { 0 } elseif ($_.Version -gt $WantedMajor) { 1 } else { 2 } } }, `
        @{ Expression = { if ($_.IsJdk) { 0 } else { 1 } } }, `
        @{ Expression = { $_.Version } ; Descending = $true }

    return ($ranked | Select-Object -First 1)
}

# ------------------------------------------------------------------------------
# Java installation
# ------------------------------------------------------------------------------

function Install-JdkWithWinget {
    param([int]$Major = 17)

    if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
        Write-Warn 'winget پیدا نشد (App Installer نصب نیست).'
        return $false
    }

    # The msstore source is flaky (0x8a15003b) and makes winget abort with
    # "Please specify one of them using the --source option". So: pin --source winget.
    $ids = @(
        "EclipseAdoptium.Temurin.$Major.JDK",
        "Microsoft.OpenJDK.$Major",
        "Amazon.Corretto.$Major.JDK",
        "Azul.Zulu.$Major.JDK",
        "BellSoft.LibericaJDK.$Major.Full"
    )

    foreach ($id in $ids) {
        Write-Host ''
        Write-Host "winget install $id  (--source winget)" -ForegroundColor Yellow

        $wingetArgs = @(
            'install', '-e', '--id', $id,
            '--source', 'winget',
            '--silent',
            '--accept-source-agreements',
            '--accept-package-agreements',
            '--disable-interactivity'
        )

        Invoke-StderrSafe { & winget @wingetArgs } | ForEach-Object { Write-Host "  $_" }
        $code = $LASTEXITCODE
        Write-Host "  winget exit code: $code" -ForegroundColor DarkGray

        if ($code -eq 0) { return $true }

        # 0x8A15000B / -1978335189 style codes: already installed or nothing to do.
        if ($code -eq -1978335189 -or $code -eq 1638) {
            Write-Warn '  -> به نظر می‌رسد همین نسخه قبلاً نصب است.'
            return $true
        }
    }

    return $false
}

function Install-JdkByDirectDownload {
    param([int]$Major = 17)

    Write-Warn 'winget جواب نداد؛ دانلود مستقیم MSI از Adoptium API ...'

    if (-not (Test-IsAdmin)) {
        Write-Warn 'برای نصب MSI باید PowerShell را به صورت Administrator باز کنید.'
        return $false
    }

    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch { }

    $url = "https://api.adoptium.net/v3/installer/latest/$Major/ga/windows/x64/jdk/hotspot/normal/eclipse"
    $msi = Join-Path $env:TEMP "temurin$Major-jdk.msi"

    Write-Host "  $url" -ForegroundColor DarkGray
    $oldProgress = $ProgressPreference
    $ProgressPreference = 'SilentlyContinue'
    try { Invoke-WebRequest -Uri $url -OutFile $msi -UseBasicParsing }
    finally { $ProgressPreference = $oldProgress }

    if (-not (Test-Path $msi)) { return $false }

    # FeatureEnvironment -> PATH, FeatureJavaHome -> JAVA_HOME (machine wide)
    $msiArgs = @(
        '/i', "`"$msi`"", '/quiet', '/norestart',
        'ADDLOCAL=FeatureMain,FeatureEnvironment,FeatureJarFileRunWith,FeatureJavaHome'
    )
    $proc = Start-Process msiexec.exe -ArgumentList $msiArgs -Wait -PassThru
    Write-Host "  msiexec exit code: $($proc.ExitCode)" -ForegroundColor DarkGray

    return ($proc.ExitCode -eq 0 -or $proc.ExitCode -eq 3010)
}

# ------------------------------------------------------------------------------
# Zip extraction (handles nested root folder + Expand-Archive failures)
# ------------------------------------------------------------------------------

function Expand-ProjectZip {
    param([Parameter(Mandatory)][string]$Zip, [Parameter(Mandatory)][string]$Dest)

    New-Item -ItemType Directory -Path $Dest -Force | Out-Null

    $ok = $false
    try {
        $oldProgress = $ProgressPreference
        $ProgressPreference = 'SilentlyContinue'
        try { Expand-Archive -Path $Zip -DestinationPath $Dest -Force -ErrorAction Stop; $ok = $true }
        finally { $ProgressPreference = $oldProgress }
    }
    catch {
        Write-Warn "Expand-Archive ناموفق بود: $($_.Exception.Message)"
    }

    if (-not $ok) {
        Write-Warn 'تلاش مجدد با tar (bsdtar) ...'
        if (-not (Get-Command tar.exe -ErrorAction SilentlyContinue)) {
            throw 'نه Expand-Archive و نه tar در دسترس نیست. ZIP را دستی استخراج کنید.'
        }
        Invoke-StderrSafe { & tar.exe -xf "$Zip" -C "$Dest" } | ForEach-Object { Write-Host "  $_" }
        if ($LASTEXITCODE -ne 0) { throw 'استخراج ZIP ناموفق بود.' }
    }
}

# ------------------------------------------------------------------------------
# Main
# ------------------------------------------------------------------------------

try {
    Write-Host ''
    Write-Host '==============================================' -ForegroundColor Cyan
    Write-Host ' Fix Java + Build Vizitor 2.17.1 (Luxury AI) ' -ForegroundColor Cyan
    Write-Host '==============================================' -ForegroundColor Cyan

    # ---------------- 1) JAVA ----------------
    Write-Step 'Step 1/5  Detecting JDK' 'Yellow'

    Update-SessionEnvironment
    $java = Find-BestJava -WantedMajor $JavaMajor

    if (-not $java -and -not $SkipJavaInstall) {
        Write-Warn "JDK پیدا نشد. نصب خودکار JDK $JavaMajor ..."
        if (-not (Test-IsAdmin)) {
            Write-Warn 'توجه: PowerShell شما Administrator نیست؛ نصب ماشین‌-wide ممکن است رد شود.'
        }

        $installed = Install-JdkWithWinget -Major $JavaMajor
        if (-not $installed) { $installed = Install-JdkByDirectDownload -Major $JavaMajor }
        if (-not $installed) { Write-Warn 'نصب خودکار موفق نبود؛ دوباره اسکن می‌کنم ...' }

        # Critical: pick up the PATH/JAVA_HOME the installer just wrote to the registry.
        Update-SessionEnvironment
        $java = Find-BestJava -WantedMajor $JavaMajor
    }

    if (-not $java) {
        throw @"
JDK پیدا نشد.
راه حل دستی:
  winget install -e --id EclipseAdoptium.Temurin.17.JDK --source winget
یا از https://adoptium.net/temurin/releases/?version=17 دانلود و نصب کنید،
سپس PowerShell را ببندید و دوباره اجرا کنید.
"@
    }

    if (-not $java.IsJdk) {
        Write-Warn "هشدار: $($java.Exe) یک JRE است (javac.exe ندارد) - build احتمالاً شکست می‌خورد."
    }
    if ($java.Version -lt $JavaMajor) {
        throw "Java نسخه $($java.Version) پیدا شد ولی Gradle/AGP به JDK $JavaMajor یا بالاتر نیاز دارد. مسیر: $($java.Exe)"
    }
    if ($java.Version -gt $JavaMajor) {
        Write-Warn "نکته: JDK $($java.Version) استفاده می‌شود (نه $JavaMajor). اگر Gradle خطا داد، JDK $JavaMajor نصب کنید."
    }

    $javaExe  = $java.Exe
    $javaBin  = Split-Path $javaExe -Parent
    $javaHome = $java.Home

    $env:JAVA_HOME = $javaHome
    $env:Path      = "$javaBin;$env:Path"

    if ($PersistEnv) {
        [Environment]::SetEnvironmentVariable('JAVA_HOME', $javaHome, 'User')
        $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
        if (($userPath -split ';') -notcontains $javaBin) {
            [Environment]::SetEnvironmentVariable('Path', "$javaBin;$userPath", 'User')
        }
        Write-Ok 'JAVA_HOME و PATH به صورت دائمی (User) ذخیره شد.'
    }

    Write-Ok "JAVA_HOME = $javaHome"
    Write-Ok "java.exe  = $javaExe"
    Write-Ok "JDK       = $($java.Version)  (IsJdk=$($java.IsJdk))"
    Write-Host ''
    (Invoke-StderrSafe { & $javaExe -version 2>&1 }) | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkGray }

    # ---------------- 2) PROJECT ----------------
    Write-Step 'Step 2/5  Preparing project' 'Yellow'

    $desktop = [Environment]::GetFolderPath('Desktop')
    $project = Join-Path $desktop $ProjectName
    $zip     = Join-Path $desktop $ZipName

    if (-not (Test-Path (Join-Path $project 'gradlew.bat'))) {
        if (-not (Test-Path $zip)) {
            throw "فایل $ZipName روی Desktop پیدا نشد.`nمسیر مورد انتظار: $zip"
        }

        Write-Host "Extracting $ZipName ..." -ForegroundColor Yellow

        $tmp = Join-Path $desktop ".${ProjectName}-extract-tmp"
        if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }

        Expand-ProjectZip -Zip $zip -Dest $tmp

        # The zip may or may not contain a single top-level folder -> find gradlew.bat.
        $wrapper = Get-ChildItem -LiteralPath $tmp -Filter 'gradlew.bat' -Recurse -File -ErrorAction SilentlyContinue |
                   Sort-Object @{ Expression = { ($_.FullName -split '[\\/]').Count } } |
                   Select-Object -First 1

        if (-not $wrapper) {
            Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
            throw 'بعد از استخراج، gradlew.bat پیدا نشد. آیا ZIP درست است؟'
        }

        $srcRoot = $wrapper.DirectoryName
        if (Test-Path $project) { Remove-Item $project -Recurse -Force }
        Move-Item -LiteralPath $srcRoot -Destination $project -Force
        if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue }
    }

    Set-Location $project
    Write-Ok "Project: $project"

    if (-not (Test-Path (Join-Path $project 'gradle\wrapper\gradle-wrapper.jar'))) {
        throw 'gradle\wrapper\gradle-wrapper.jar وجود ندارد (ZIP ناقص است). Gradle wrapper نمی‌تواند اجرا شود.'
    }

    # ---------------- 3) ANDROID SDK ----------------
    Write-Step 'Step 3/5  Checking Android SDK' 'Yellow'

    $sdk = $null
    $sdkFallback = $null
    foreach ($candidate in @($env:ANDROID_HOME, $env:ANDROID_SDK_ROOT, (Join-Path $env:LOCALAPPDATA 'Android\Sdk'), 'C:\Android\Sdk')) {
        if (-not $candidate -or -not (Test-Path $candidate)) { continue }
        $full = (Resolve-Path $candidate).Path
        if (Test-Path (Join-Path $full 'platforms')) { $sdk = $full; break }   # a real SDK
        if (-not $sdkFallback) { $sdkFallback = $full }
    }
    if (-not $sdk) { $sdk = $sdkFallback }

    if ($sdk) {
        $env:ANDROID_HOME     = $sdk
        $env:ANDROID_SDK_ROOT = $sdk
        Write-Ok "ANDROID_HOME = $sdk"

        # Only (re)write local.properties when it is missing or points nowhere.
        $localProps = Join-Path $project 'local.properties'
        $needWrite  = $true
        if (Test-Path $localProps) {
            $existing = (Get-Content $localProps | Where-Object { $_ -match '^\s*sdk\.dir\s*=' } | Select-Object -First 1)
            if ($existing) {
                $existingPath = ($existing -split '=', 2)[1].Trim().Replace('\\', '\').Replace('\:', ':')
                if (Test-Path $existingPath) { $needWrite = $false; Write-Ok "local.properties -> $existingPath" }
            }
        }
        if ($needWrite) {
            $escaped = $sdk.Replace('\', '\\').Replace(':', '\:')
            Set-Content -Path $localProps -Value "sdk.dir=$escaped" -Encoding ASCII
            Write-Ok "local.properties نوشته شد: sdk.dir=$sdk"
        }
    }
    elseif (-not $AllowMissingSdk) {
        throw @"
Android SDK پیدا نشد. build به آن نیاز دارد.
راه حل: Android Studio نصب کنید، یا SDK را در مسیر پیش‌فرض بگذارید:
  $env:LOCALAPPDATA\Android\Sdk
سپس دوباره اجرا کنید (یا با -AllowMissingSdk ادامه دهید).
"@
    }
    else {
        Write-Warn 'Android SDK پیدا نشد - ادامه می‌دهم (احتمال خطا در build).'
    }

    # ---------------- 4) BUILD ----------------
    Write-Step "Step 4/5  Gradle: $GradleTask" 'Yellow'

    $gradleArgs = @($GradleTask, '--no-daemon', '--stacktrace') + $ExtraGradleArgs
    Write-Host "  .\gradlew.bat $($gradleArgs -join ' ')" -ForegroundColor DarkGray
    Write-Host ''

    $oldEap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & .\gradlew.bat @gradleArgs }
    finally { $ErrorActionPreference = $oldEap }

    $buildExit = $LASTEXITCODE
    if ($buildExit -ne 0) {
        throw @"
Gradle build شکست خورد (exit code $buildExit).
علت‌های رایج:
  - SDK licenses پذیرفته نشده:  & "$sdk\cmdline-tools\latest\bin\sdkmanager.bat" --licenses
  - نسخه Gradle/AGP با JDK سازگار نیست (JDK $($java.Version) در حال استفاده است)
  - signing config برای release تعریف نشده
  - اولین اجرا باید Gradle distribution را دانلود کند (اینترنت لازم است)
برای جزئیات بیشتر:  .\gradlew.bat $GradleTask --no-daemon --info
"@
    }
    Write-Ok 'BUILD SUCCESSFUL'

    # ---------------- 5) APK ----------------
    Write-Step 'Step 5/5  Collecting APK' 'Yellow'

    $apkDir = Join-Path $project 'app\build\outputs\apk'
    if (-not (Test-Path $apkDir)) { $apkDir = Join-Path $project 'app\build\outputs' }

    $apkFile = Get-ChildItem -Path $apkDir -Filter '*.apk' -Recurse -File -ErrorAction SilentlyContinue |
               Where-Object { $_.FullName -match '\\release\\' } |
               Sort-Object LastWriteTime -Descending |
               Select-Object -First 1

    if (-not $apkFile) {
        $apkFile = Get-ChildItem -Path $apkDir -Filter '*.apk' -Recurse -File -ErrorAction SilentlyContinue |
                   Sort-Object LastWriteTime -Descending | Select-Object -First 1
    }

    if (-not $apkFile) { throw 'Build موفق بود ولی هیچ APK پیدا نشد.' }

    if ($apkFile.Name -match 'unsigned') {
        Write-Warn "APK امضا نشده است: $($apkFile.Name) - برای نصب روی گوشی به signing نیاز دارید."
    }

    $finalApk = Join-Path $desktop $OutputApkName
    Copy-Item -LiteralPath $apkFile.FullName -Destination $finalApk -Force

    Write-Host ''
    Write-Host '============================================' -ForegroundColor Green
    Write-Host ' APK READY' -ForegroundColor Green
    Write-Host '============================================' -ForegroundColor Green
    Write-Host ''
    Write-Info $finalApk
    Write-Host ("  Size: {0:N2} MB" -f ($apkFile.Length / 1MB)) -ForegroundColor DarkGray
    Write-Host ''

    $script:ExitCode = 0
    try { Start-Process explorer.exe -ArgumentList "/select,`"$finalApk`"" } catch { }
}
catch {
    Write-Host ''
    Write-Host '============================================' -ForegroundColor Red
    Write-Host ' FAILED' -ForegroundColor Red
    Write-Host '============================================' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    if ($_.ScriptStackTrace) { Write-Host $_.ScriptStackTrace -ForegroundColor DarkGray }
    $script:ExitCode = 1
}
finally {
    if ($Pause) {
        Write-Host ''
        Read-Host 'Press ENTER to exit' | Out-Null
    }
    exit $script:ExitCode
}
