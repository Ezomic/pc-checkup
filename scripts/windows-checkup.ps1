# Read-only Windows checkup. Writes a Markdown report to the Desktop.
# The scan runs once, only reads information, and installs and changes nothing: it changes no
# setting, starts no service, schedules nothing and leaves nothing running afterwards.
# Usage: powershell -ExecutionPolicy Bypass -File windows-checkup.ps1 [-Token <token>] [-Code <code>] [-Url <url>]
# Works on Windows PowerShell 5.1 and PowerShell 7. Needs no admin rights; sections that
# do need them say "unavailable (needs admin)". Keep this file ASCII only: 5.1 reads a
# file without a byte order mark as ANSI.
#
# Optional upload: with a token the finished report is also sent to PC Checkup. The token is
# read from, in this order: -Token, the PC_CHECKUP_TOKEN environment variable, or a file named
# pc-checkup.token next to this script. The site is $DefaultUrl below; -Url or PC_CHECKUP_URL
# override it (https only, plus http://localhost for testing). The token is never written to
# the report or printed.
#
# Without a token the script takes the 6 digit code of "Start a scan" in the app: -Code, the
# PC_CHECKUP_CODE environment variable, or a prompt when run in a console (an empty answer
# skips the upload). A token always wins over a code.

param(
    [string]$Token,
    [string]$Code,
    [string]$Url
)

$ErrorActionPreference = 'Stop'
$DefaultUrl = 'https://pc-checkup.thijssensoftware.nl'

if ($PSVersionTable.PSEdition -eq 'Core' -and -not $IsWindows) {
    Write-Host 'This script only runs on Windows. Use mac-checkup.sh on a Mac.'
    exit 1
}

$Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$HostName = $env:COMPUTERNAME
$Desktop = [Environment]::GetFolderPath('Desktop')
if (-not $Desktop -or -not (Test-Path -LiteralPath $Desktop)) { $Desktop = $env:USERPROFILE }
$Out = Join-Path $Desktop "windows-checkup-$Stamp.md"
$Flags = New-Object System.Collections.Generic.List[string]
$Body = New-Object System.Collections.Generic.List[string]

$PlaceholderSerials = @(
    'to be filled by o.e.m.', 'o.e.m.', 'oem', 'default string', 'system serial number',
    'chassis serial number', 'not specified', 'not applicable', 'not defined', 'none',
    'unknown', 'n/a', 'invalid', 'serialnumber', 'serial number', '123456789', '0123456789'
)
$PlaceholderUuids = @(
    '03000200-0400-0500-0006-000700080009', '00020003-0004-0005-0006-000700080009'
)
$LaptopChassis = 8, 9, 10, 11, 12, 14, 18, 21, 22, 30, 31, 32
$DesktopChassis = 3, 4, 5, 6, 7, 13, 15, 16, 17, 23, 24, 25, 35, 36

# ---------- Helpers ----------
function One-Line($Text) {
    if ($null -eq $Text) { return '' }
    return ([string]$Text -replace '\s+', ' ').Trim()
}

function Add-Flag([string]$Text) {
    $line = One-Line $Text
    if (-not $Flags.Contains($line)) { $Flags.Add($line) }
}

function Explain-Error($ErrorRecord) {
    $message = One-Line $ErrorRecord.Exception.Message
    $denied = 'denied|privilege|elevat|administrator|0x80041003|0x80070005|\baccess\b|not have the required|insufficient'
    if ($ErrorRecord.Exception -is [System.UnauthorizedAccessException] -or $message -match $denied) {
        return 'unavailable (needs admin)'
    }
    if ($message.Length -gt 120) { $message = $message.Substring(0, 120) }
    return "unavailable ($message)"
}

# Runs a block and never throws: Value is what it returned, Note says why it could not.
function Try-Run([scriptblock]$Block) {
    try {
        $value = & $Block
        return [pscustomobject]@{ Value = $value; Note = $null }
    } catch {
        return [pscustomobject]@{ Value = $null; Note = (Explain-Error $_) }
    }
}

function Add-Section([string]$Title, [scriptblock]$Block) {
    $failure = $null
    $items = @()
    try {
        & $Block | ForEach-Object { $items += , $_ }
    } catch {
        $failure = Explain-Error $_
    }
    $text = (($items | Out-String -Width 200).Trim(), $failure | Where-Object { $_ }) -join "`n"
    if (-not $text) { $text = '(nothing to show)' }
    $fence = '```'
    $Body.Add("`n## $Title`n`n$fence`n$text`n$fence")
}

function Get-UsableSerial($Value) {
    $text = One-Line $Value
    if ($text -eq '' -or $PlaceholderSerials -contains $text.ToLowerInvariant() -or $text -cmatch '^(.)\1*$') { return '' }
    return $text
}

function Get-UsableUuid($Value) {
    $text = One-Line $Value
    if ($text -eq '' -or $PlaceholderUuids -contains $text.ToLowerInvariant() -or ($text -replace '-', '') -cmatch '^(.)\1*$') { return '' }
    return $text
}

function Read-BatteryReport([string]$Path) {
    [xml]$report = Get-Content -LiteralPath $Path -Raw
    $batteries = @($report.BatteryReport.Batteries.Battery)
    $design = 0; $full = 0; $cycles = $null
    foreach ($battery in $batteries) {
        $design += [int64]$battery.DesignCapacity
        $full += [int64]$battery.FullChargeCapacity
        if ($null -ne $battery.CycleCount -and "$($battery.CycleCount)" -ne '') { $cycles = [int]$battery.CycleCount }
    }
    return [pscustomobject]@{ Design = $design; Full = $full; Cycles = $cycles }
}

function Get-RegValue([string]$Path, [string]$Name) {
    return (Get-ItemProperty -LiteralPath $Path -Name $Name).$Name
}

# ---------- Upload helpers ----------
# Returns the token and where it came from, or $null: parameter, environment, then a file.
function Get-UploadToken([string]$Given, [string]$Folder) {
    $candidates = @(
        [pscustomobject]@{ Source = 'parameter'; Value = $Given },
        [pscustomobject]@{ Source = 'environment'; Value = $env:PC_CHECKUP_TOKEN }
    )
    $file = if ($Folder) { Join-Path $Folder 'pc-checkup.token' } else { $null }
    if ($file -and (Test-Path -LiteralPath $file -PathType Leaf)) {
        $first = @(Get-Content -LiteralPath $file -TotalCount 1)[0]
        $candidates += [pscustomobject]@{ Source = 'file'; Value = $first }
    }
    foreach ($candidate in $candidates) {
        $value = ([string]$candidate.Value).Trim()
        if ($value -ne '') { return [pscustomobject]@{ Source = $candidate.Source; Value = $value } }
    }
    return $null
}

function Test-UploadToken([string]$Value) { return $Value -cmatch '^[A-Za-z0-9|_.~+/=-]{1,255}$' }

function Test-ScanCode([string]$Value) { return $Value -cmatch '^[0-9]{6}$' }

# The app shows the code as "012 345", so whitespace typed or pasted along with it is dropped.
function Clean-ScanCode($Value) { return ([string]$Value) -replace '\s', '' }

# Returns the code and where it came from, or $null: parameter, environment, then a prompt
# when there is a console to ask on. An empty answer skips the upload.
function Get-ScanCode([string]$Given) {
    $candidates = @(
        [pscustomobject]@{ Source = 'parameter'; Value = $Given },
        [pscustomobject]@{ Source = 'environment'; Value = $env:PC_CHECKUP_CODE }
    )
    foreach ($candidate in $candidates) {
        $value = Clean-ScanCode $candidate.Value
        if ($value -ne '') { return [pscustomobject]@{ Source = $candidate.Source; Value = $value } }
    }
    if ([Environment]::UserInteractive -and -not [Console]::IsInputRedirected) {
        $value = Clean-ScanCode (Read-Host 'Scan code from the app, 6 digits (Enter to skip the upload)')
        if ($value -ne '') { return [pscustomobject]@{ Source = 'prompt'; Value = $value } }
    }
    return $null
}

function Test-UploadUrl([string]$Value) {
    return $Value -match '^(https://[A-Za-z0-9.-]+|http://(localhost|127\.0\.0\.1))(:[0-9]+)?(/[^\s\\?#]*)?$'
}

# The request body: {"markdown": "..."}, with a "code" first when a scan code is given, as
# UTF-8 bytes without a byte order mark.
function New-UploadBody([string]$Markdown, [string]$ScanCode) {
    $fields = [ordered]@{}
    if ($ScanCode) { $fields['code'] = $ScanCode }
    $fields['markdown'] = $Markdown
    $json = ConvertTo-Json -InputObject $fields -Compress
    return , (New-Object System.Text.UTF8Encoding($false)).GetBytes($json)
}

function Read-FailedResponse($ErrorRecord) {
    $response = $ErrorRecord.Exception.Response
    if ($null -eq $response) { return $null }
    $content = $null
    if ($ErrorRecord.ErrorDetails -and $ErrorRecord.ErrorDetails.Message) {
        $content = $ErrorRecord.ErrorDetails.Message
    } elseif ($response -is [System.Net.WebResponse]) {
        try {
            $reader = New-Object System.IO.StreamReader($response.GetResponseStream(), [System.Text.Encoding]::UTF8)
            $content = $reader.ReadToEnd()
            $reader.Dispose()
        } catch { $content = $null }
    }
    return [pscustomobject]@{ Status = [int]$response.StatusCode; Content = $content }
}

# Never throws: Status is the HTTP status (0 when there was none), Data the parsed JSON body.
function Send-Report([string]$Markdown, [string]$BaseUrl, [string]$ApiToken, [string]$ScanCode) {
    $result = [pscustomobject]@{ Status = 0; Data = $null; Failure = $null }
    $content = $null
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        $headers = @{ Accept = 'application/json' }
        $path = '/api/scans'
        if ($ApiToken) {
            $headers['Authorization'] = "Bearer $ApiToken"
            $path = '/api/reports'
            $ScanCode = ''
        }
        $response = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl$path" -Headers $headers `
            -ContentType 'application/json; charset=utf-8' -Body (New-UploadBody $Markdown $ScanCode) -TimeoutSec 30 -MaximumRedirection 0
        $result.Status = [int]$response.StatusCode
        $content = $response.Content
    } catch {
        $failed = Read-FailedResponse $_
        if ($failed) {
            $result.Status = $failed.Status
            $content = $failed.Content
        } else {
            $result.Failure = One-Line $_.Exception.Message
        }
    }
    try { if ($content) { $result.Data = ConvertFrom-Json -InputObject ([string]$content) } } catch { $result.Data = $null }
    return $result
}

function Get-RejectionText($Data) {
    $parts = New-Object System.Collections.Generic.List[string]
    if ($Data -and $Data.message) { $parts.Add((One-Line $Data.message)) }
    if ($Data -and $Data.errors) {
        foreach ($property in $Data.errors.PSObject.Properties) {
            foreach ($text in @($property.Value)) { $parts.Add((One-Line $text)) }
        }
    }
    if ($parts.Count -eq 0) { return 'no reason given' }
    return (($parts | Select-Object -Unique) -join ' | ')
}

function Show-UploadResult($Result, [string]$BaseUrl, [bool]$WithCode = $false) {
    $machineId = $null
    if ($Result.Data -and "$($Result.Data.machine_id)" -match '^\d+$') { $machineId = $Result.Data.machine_id }
    $machineText = if ($machineId) { "machine $machineId, $BaseUrl/machines/$machineId" } else { 'machine unknown' }
    switch ($Result.Status) {
        0   { Write-Host "Upload failed: could not reach $BaseUrl ($($Result.Failure))." }
        201 { Write-Host "Uploaded as a new report: $machineText" }
        200 { Write-Host "Already uploaded: this identical report is already in PC Checkup ($machineText)." }
        401 { Write-Host 'Upload failed: the token was not accepted (invalid, expired or revoked). Create a new one in the app under Settings, API tokens.' }
        403 { Write-Host 'Upload failed: the token does not have the Write ability. Create a new token with the Write ability.' }
        422 { Write-Host "The server rejected the report: $(Get-RejectionText $Result.Data)" }
        429 {
            if ($WithCode) { Write-Host 'Upload skipped: too many wrong scan codes were tried recently, wait a while and start a new scan.' }
            else { Write-Host 'Upload skipped: the server is rate limiting this token, try again in a minute.' }
        }
        default { Write-Host "Upload failed: the server answered HTTP $($Result.Status)." }
    }
}

# Called once the report is saved: uploads it, or says how to. Never throws.
function Invoke-ReportUpload([string]$Markdown, $Found, $FoundCode, [string]$GivenUrl) {
    if (-not $Found -and -not $FoundCode) {
        Write-Host 'To upload this report: choose Start a scan in the app and run again with -Code (or PC_CHECKUP_CODE set), or create a token with only the Write ability (Settings, API tokens) and set PC_CHECKUP_TOKEN or put a pc-checkup.token file next to this script.'
        return
    }
    try {
        $base = $GivenUrl
        if (-not $base) { $base = $env:PC_CHECKUP_URL }
        if (-not $base) { $base = $DefaultUrl }
        $base = ([string]$base).Trim().TrimEnd('/')
        if (-not (Test-UploadUrl $base)) {
            Write-Host 'Upload skipped: the site address must start with https:// (http://localhost is allowed for testing).'
        } elseif ($Found) {
            if (Test-UploadToken $Found.Value) {
                Show-UploadResult (Send-Report $Markdown $base $Found.Value '') $base
            } else {
                Write-Host "Upload skipped: the token contains characters a token never has. Check the $($Found.Source) it came from."
            }
        } elseif (Test-ScanCode $FoundCode.Value) {
            Show-UploadResult (Send-Report $Markdown $base '' $FoundCode.Value) $base $true
        } else {
            Write-Host "Upload skipped: a scan code is exactly 6 digits. Check the $($FoundCode.Source) it came from."
        }
    } catch {
        Write-Host "Upload failed unexpectedly: $(One-Line $_.Exception.Message)"
    }
    if ($Found) {
        Write-Host 'Token safety: use a token with only the Write ability and a short lifetime, and delete the token from any machine that is not yours.'
    }
}

$IsAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

$found = $null
$foundCode = $null
try { $found = Get-UploadToken $Token $PSScriptRoot } catch { Write-Host "Upload skipped: could not read the token ($(One-Line $_.Exception.Message))." }
if (-not $found) {
    try { $foundCode = Get-ScanCode $Code } catch { Write-Host "Upload skipped: could not read the scan code ($(One-Line $_.Exception.Message))." }
}

Write-Host 'Running checkup, this can take a minute or two...'

# ---------- Identity ----------
$cs = (Try-Run { Get-CimInstance Win32_ComputerSystem }).Value
$os = (Try-Run { Get-CimInstance Win32_OperatingSystem }).Value
$bios = (Try-Run { Get-CimInstance Win32_BIOS }).Value
$product = (Try-Run { Get-CimInstance Win32_ComputerSystemProduct }).Value
$board = (Try-Run { Get-CimInstance Win32_BaseBoard }).Value
$cpus = @((Try-Run { Get-CimInstance Win32_Processor }).Value)
$batteries = @((Try-Run { Get-CimInstance Win32_Battery }).Value | Where-Object { $_ })

$serial = Get-UsableSerial $bios.SerialNumber
if ($serial -eq '') { $serial = Get-UsableSerial $product.IdentifyingNumber }
$uuid = Get-UsableUuid $product.UUID

$chassis = @((Try-Run { (Get-CimInstance Win32_SystemEnclosure).ChassisTypes }).Value)
if (@($chassis | Where-Object { $DesktopChassis -contains $_ }).Count -gt 0) {
    $deviceType = 'desktop'
} elseif (@($chassis | Where-Object { $LaptopChassis -contains $_ }).Count -gt 0) {
    $deviceType = 'laptop'
} elseif ($batteries.Count -gt 0) {
    $deviceType = 'laptop'
} else {
    $deviceType = 'desktop'
}

$manufacturer = One-Line $cs.Manufacturer
$modelCode = One-Line $cs.Model
$placeholderModel = '^$|to be filled|default string|system product name|^system$|not specified'
if ($modelCode -match $placeholderModel) {
    $modelCode = (One-Line "$($board.Manufacturer) $($board.Product)")
    $manufacturer = ''
}
$friendly = One-Line $product.Version
if ($manufacturer -match 'lenovo' -and $friendly -ne '' -and $friendly -notmatch $placeholderModel -and $friendly -notmatch '^(none|1\.0|not available)$') {
    $model = "Lenovo $friendly ($modelCode)"
} elseif ($manufacturer -ne '' -and -not $modelCode.StartsWith($manufacturer, [StringComparison]::OrdinalIgnoreCase)) {
    $model = "$manufacturer $modelCode"
} else {
    $model = $modelCode
}

$cpuName = One-Line ($cpus | Select-Object -First 1).Name
$cores = ($cpus | Measure-Object -Property NumberOfCores -Sum).Sum
$threads = ($cpus | Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum
$ramGb = if ($cs.TotalPhysicalMemory) { [math]::Round($cs.TotalPhysicalMemory / 1GB) } else { $null }

$currentVersion = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
$displayVersion = (Try-Run { Get-RegValue $currentVersion 'DisplayVersion' }).Value
$ubr = (Try-Run { Get-RegValue $currentVersion 'UBR' }).Value
$buildNumber = [int]($os.BuildNumber)
$osCaption = One-Line ($os.Caption -replace '^Microsoft\s+', '')
if (-not $displayVersion) { $displayVersion = (Try-Run { Get-RegValue $currentVersion 'ReleaseId' }).Value }
$osLine = One-Line "$osCaption $displayVersion (build $($os.BuildNumber).$ubr)"

# ---------- Battery ----------
$batteryDesign = $null; $batteryFull = $null; $batteryCycles = $null; $batteryNote = $null
if ($batteries.Count -gt 0) {
    $static = Try-Run { Get-CimInstance -Namespace root\wmi -ClassName BatteryStaticData }
    $fullCap = Try-Run { Get-CimInstance -Namespace root\wmi -ClassName BatteryFullChargedCapacity }
    $cycleCount = Try-Run { Get-CimInstance -Namespace root\wmi -ClassName BatteryCycleCount }
    $batteryDesign = ($static.Value | Measure-Object -Property DesignedCapacity -Sum).Sum
    $batteryFull = ($fullCap.Value | Measure-Object -Property FullChargedCapacity -Sum).Sum
    $batteryCycles = ($cycleCount.Value | Select-Object -First 1).CycleCount
    if ($batteryCycles -eq 0) { $batteryCycles = $null }

    if (-not $batteryDesign -or -not $batteryFull -or $null -eq $batteryCycles) {
        $reportPath = Join-Path ([IO.Path]::GetTempPath()) "pcc-battery-$PID.xml"
        $fromReport = Try-Run {
            powercfg /batteryreport /xml /output $reportPath | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'powercfg could not write a battery report' }
            Read-BatteryReport $reportPath
        }
        Remove-Item -LiteralPath $reportPath -ErrorAction SilentlyContinue
        if ($fromReport.Value) {
            if (-not $batteryDesign) { $batteryDesign = $fromReport.Value.Design }
            if (-not $batteryFull) { $batteryFull = $fromReport.Value.Full }
            if ($null -eq $batteryCycles) { $batteryCycles = $fromReport.Value.Cycles }
        } else {
            $batteryNote = $fromReport.Note
        }
    }
}
$batteryPct = $null
if ($batteryDesign -and $batteryFull) { $batteryPct = [math]::Round(100 * $batteryFull / $batteryDesign) }

if ($batteries.Count -gt 0) {
    if ($batteryCycles -and $batteryCycles -ge 800) { Add-Flag "Battery has $batteryCycles cycles (high)." }
    if ($null -ne $batteryPct -and $batteryPct -lt 80) { Add-Flag "Battery full-charge capacity is $batteryPct% of design (below 80%)." }
}

# ---------- Storage ----------
$systemDrive = if ($env:SystemDrive) { $env:SystemDrive } else { 'C:' }
$physical = Try-Run { Get-PhysicalDisk | Select-Object FriendlyName, MediaType, BusType, @{ N = 'SizeGB'; E = { [math]::Round($_.Size / 1GB) } }, HealthStatus, OperationalStatus }
$diskFallback = $null
if (-not $physical.Value) {
    $diskFallback = Try-Run { Get-CimInstance Win32_DiskDrive | Select-Object Model, InterfaceType, @{ N = 'SizeGB'; E = { [math]::Round($_.Size / 1GB) } }, Status }
}
$volumes = @((Try-Run { Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' }).Value)

foreach ($disk in @($physical.Value)) {
    if ($disk -and "$($disk.HealthStatus)" -ne 'Healthy') { Add-Flag "Disk '$(One-Line $disk.FriendlyName)' health is '$($disk.HealthStatus)'." }
}
foreach ($disk in @($diskFallback.Value)) {
    if ($disk -and "$($disk.Status)" -ne 'OK') { Add-Flag "Disk '$(One-Line $disk.Model)' status is '$($disk.Status)'." }
}
$spinning = @($physical.Value | Where-Object { "$($_.MediaType)" -eq 'HDD' })
if ($spinning.Count -gt 0 -and $spinning.Count -eq @($physical.Value).Count) { Add-Flag 'Only spinning hard drives found (no SSD), so the PC will feel slow.' }

$freePct = $null
foreach ($volume in $volumes) {
    if (-not $volume -or -not $volume.Size) { continue }
    $pct = [math]::Floor(100 * $volume.FreeSpace / $volume.Size)
    if ($volume.DeviceID -eq $systemDrive) { $freePct = $pct }
    if ($pct -lt 10) { Add-Flag "Only about $pct% of drive $($volume.DeviceID) is free." }
}

$reliability = Try-Run { Get-PhysicalDisk | Get-StorageReliabilityCounter | Select-Object DeviceId, Wear, Temperature, PowerOnHours, ReadErrorsTotal, WriteErrorsTotal }
foreach ($counter in @($reliability.Value)) {
    if ($counter -and $null -ne $counter.Wear -and $counter.Wear -ge 80) { Add-Flag "Disk $($counter.DeviceId) SSD wear is $($counter.Wear)% (near end of life)." }
}

# ---------- Memory, CPU, uptime ----------
$memory = Try-Run { Get-CimInstance Win32_PerfFormattedData_PerfOS_Memory }
$availablePct = $null
if ($memory.Value -and $os.TotalVisibleMemorySize) {
    $availablePct = [math]::Floor(100 * ($memory.Value.AvailableBytes / 1KB) / $os.TotalVisibleMemorySize)
    if ($availablePct -lt 15) { Add-Flag "Only $availablePct% of memory is available (high memory pressure)." }
}
if ($ramGb -and $ramGb -lt 8) { Add-Flag "Only $ramGb GB of RAM." }


$uptimeDays = $null
if ($os.LastBootUpTime) {
    $uptimeDays = [int][math]::Floor(((Get-Date) - $os.LastBootUpTime).TotalDays)
    if ($uptimeDays -ge 30) { Add-Flag "Up for $uptimeDays days since the last full boot (Fast Startup can inflate this)." }
}

# ---------- Security ----------
$secureBootKey = 'HKLM:\SYSTEM\CurrentControlSet\Control\SecureBoot\State'
$secureBoot = 'unavailable'
if (-not (Test-Path -LiteralPath $secureBootKey)) {
    $secureBoot = 'not available (legacy BIOS boot or no UEFI Secure Boot)'
    Add-Flag 'Secure Boot is not available (legacy BIOS boot or unsupported firmware).'
} else {
    $sb = Try-Run { Get-RegValue $secureBootKey 'UEFISecureBootEnabled' }
    if ($sb.Note) {
        $secureBoot = $sb.Note
    } elseif ($sb.Value -eq 1) {
        $secureBoot = 'on'
    } else {
        $secureBoot = 'OFF'
        Add-Flag 'Secure Boot is OFF.'
    }
}

$tpmSummary = 'unavailable'
$tpm = if ($IsAdmin) { Try-Run { Get-Tpm } } else { [pscustomobject]@{ Value = $null; Note = 'unavailable (needs admin)' } }
if ($tpm.Value) {
    if ($tpm.Value.TpmPresent) {
        $tpmSummary = "present, ready: $($tpm.Value.TpmReady), enabled: $($tpm.Value.TpmEnabled)"
        if (-not $tpm.Value.TpmReady) { Add-Flag 'TPM is present but not ready.' }
    } else {
        $tpmSummary = 'not found'
        Add-Flag 'No TPM found (Windows 11 needs TPM 2.0).'
    }
} else {
    $tpmDevice = Try-Run { Get-CimInstance Win32_PnPEntity | Where-Object { $_.PNPClass -eq 'SecurityDevices' -and $_.DeviceID -match '^ACPI\\(MSFT0101|MSFT0200|IFX|NTC|STM|INTC|AMDI|BCM)' } | Select-Object -First 1 }
    if ($tpmDevice.Value) {
        $tpmSummary = "present ($(One-Line $tpmDevice.Value.Name)), details unavailable (needs admin)"
    } else {
        $tpmSummary = 'unavailable (needs admin to read the TPM; no TPM device was visible without it)'
    }
}

# Without admin, Explorer's drive property is the only readable source: 1 is on, 0 and 2 are off.
$bitLocker = 'unavailable'
$bl = if ($IsAdmin) { Try-Run { Get-BitLockerVolume -MountPoint $systemDrive } } else { [pscustomobject]@{ Value = $null; Note = 'unavailable (needs admin)' } }
if ($bl.Value) {
    $status = "$($bl.Value.ProtectionStatus)"
    $bitLocker = "$status ($($bl.Value.VolumeStatus))"
    if ($status -ne 'On') { Add-Flag "BitLocker is not protecting $systemDrive ($status)." }
} else {
    $explorer = Try-Run { (New-Object -ComObject Shell.Application).NameSpace("$systemDrive\").Self.ExtendedProperty('System.Volume.BitLockerProtection') }
    if ($null -ne $explorer.Value) {
        switch ([int]$explorer.Value) {
            1 { $bitLocker = 'on' }
            { $_ -eq 0 -or $_ -eq 2 } { $bitLocker = 'OFF'; Add-Flag "BitLocker is OFF on $systemDrive." }
            default { $bitLocker = "state code $($explorer.Value) (see Explorer drive properties)" }
        }
    } else {
        $bitLocker = $bl.Note
    }
}

$firewall = Try-Run { Get-NetFirewallProfile | Select-Object Name, Enabled }
foreach ($fw in @($firewall.Value)) {
    if ($fw -and "$($fw.Enabled)" -eq 'False') { Add-Flag "Windows Firewall is off for the $($fw.Name) profile." }
}

$defender = Try-Run { Get-MpComputerStatus }
$otherAv = Try-Run { Get-CimInstance -Namespace root\SecurityCenter2 -ClassName AntiVirusProduct | Where-Object { $_.displayName -notmatch 'Windows Defender|Microsoft Defender' } | Select-Object -ExpandProperty displayName }
$thirdPartyAv = @($otherAv.Value | Where-Object { $_ })
if ($defender.Value) {
    if (-not $defender.Value.RealTimeProtectionEnabled -and $thirdPartyAv.Count -eq 0) { Add-Flag 'Defender real-time protection is OFF and no other antivirus was found.' }
    if ($defender.Value.AntivirusSignatureAge -gt 7 -and $thirdPartyAv.Count -eq 0) { Add-Flag "Defender signatures are $($defender.Value.AntivirusSignatureAge) days old." }
    if ($defender.Value.IsTamperProtected -eq $false) { Add-Flag 'Defender tamper protection is off.' }
}

$licence = Try-Run { Get-CimInstance SoftwareLicensingProduct -Filter "ApplicationId='55c92734-d682-4d71-983e-d6ec3f16059f' AND PartialProductKey IS NOT NULL" | Select-Object -First 1 }
$licenceStates = 'Unlicensed', 'Licensed', 'Initial grace period', 'Additional grace period', 'Non-genuine grace period', 'Notification', 'Extended grace period'
$activation = 'unavailable'
if ($licence.Value) {
    $stateName = $licenceStates[[int]$licence.Value.LicenseStatus]
    $activation = "$stateName ($(One-Line $licence.Value.Description))"
    if ($licence.Value.LicenseStatus -ne 1) { Add-Flag "Windows is not activated (status: $stateName)." }
    if ("$($licence.Value.Description)" -match 'VOLUME') { Add-Flag "Windows uses a volume licence ($(One-Line $licence.Value.Description)), usually an organisation's." }
} elseif ($licence.Note) {
    $activation = $licence.Note
}

$securityAgents = Try-Run {
    Get-Process | Where-Object { $_.ProcessName -match 'falcon|sophos|norton|mcafee|avast|avg|malwarebytes|mbam|crowdstrike|sentinel|carbonblack|^ekrn$|kaspersky|^avp$|bitdefender|bdagent|webroot|symantec|trendmicro|teamviewer|anydesk|screenconnect|connectwise|splashtop|rustdesk|logmein|ninjarmm|datto|kaseya|atera' } |
        Select-Object -ExpandProperty ProcessName -Unique | Sort-Object
}
if (@($securityAgents.Value).Count -gt 0) { Add-Flag "Security, management or remote access agents running (can slow a PC or give someone else access): $(@($securityAgents.Value) -join ', ')" }

$dsreg = Try-Run { dsregcmd /status }
if ($dsreg.Value) {
    $dsregText = @($dsreg.Value) -join "`n"
    if ($dsregText -match '(?m)^[ \t]*AzureAdJoined[ \t]*:[ \t]*YES') { Add-Flag 'Joined to Entra ID / Azure AD (managed by an organisation).' }
    if ($dsregText -match '(?m)^[ \t]*MdmUrl[ \t]*:[ \t]*\S') { Add-Flag 'Enrolled in MDM (managed by an organisation).' }
}
if ($cs.PartOfDomain) { Add-Flag "Joined to the domain $(One-Line $cs.Domain) (managed by an organisation)." }

# ---------- Windows ----------
if ($buildNumber -gt 0 -and $buildNumber -lt 22000) { Add-Flag "$osCaption reached end of support on 14 October 2025, so it only gets updates with a paid extension." }

$updatesKey = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update'
$rebootReasons = New-Object System.Collections.Generic.List[string]
if (Test-Path -LiteralPath "$updatesKey\RebootRequired") { $rebootReasons.Add('Windows Update is waiting for a restart') }
if (Test-Path -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') { $rebootReasons.Add('component servicing is waiting for a restart') }
$renames = Try-Run { Get-RegValue 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' 'PendingFileRenameOperations' }
if ($rebootReasons.Count -gt 0) { Add-Flag "A restart is pending ($($rebootReasons -join '; '))." }

$hotfixes = Try-Run { @(Get-HotFix | Where-Object { $_.InstalledOn } | Sort-Object InstalledOn -Descending) }
$lastInstall = $null
if ($hotfixes.Value -and @($hotfixes.Value).Count -gt 0) { $lastInstall = @($hotfixes.Value)[0].InstalledOn }
$installAge = $null
if ($lastInstall) {
    $installAge = [int][math]::Floor(((Get-Date) - $lastInstall).TotalDays)
    if ($installAge -gt 60) { Add-Flag "No Windows update has been installed for $installAge days (newest: $($lastInstall.ToString('yyyy-MM-dd')))." }
}

$pendingUpdates = Try-Run {
    $job = Start-Job -ScriptBlock {
        $session = New-Object -ComObject Microsoft.Update.Session
        $found = $session.CreateUpdateSearcher().Search('IsInstalled=0 and IsHidden=0')
        @($found.Updates | ForEach-Object { $_.Title })
    }
    if (-not (Wait-Job $job -Timeout 120)) {
        Stop-Job $job
        Remove-Job $job -Force
        throw 'search for Windows updates timed out after 120 seconds'
    }
    try { $titles = Receive-Job $job -ErrorAction Stop } finally { Remove-Job $job -Force }
    , @($titles)
}
if ($pendingUpdates.Value -and @($pendingUpdates.Value).Count -gt 0) { Add-Flag "Windows updates are pending (see Windows Update section): $(@($pendingUpdates.Value).Count)." }

$startup = Try-Run { @(Get-CimInstance Win32_StartupCommand) }
if ($startup.Value -and @($startup.Value).Count -gt 15) { Add-Flag "$(@($startup.Value).Count) programs start with Windows (slows boot)." }

$badDevices = Try-Run { @(Get-CimInstance Win32_PnPEntity -Filter 'ConfigManagerErrorCode <> 0 AND ConfigManagerErrorCode <> 22 AND ConfigManagerErrorCode <> 24') }
if ($badDevices.Value -and @($badDevices.Value).Count -gt 0) { Add-Flag "$(@($badDevices.Value).Count) device(s) report a driver problem in Device Manager (see Devices with problems section)." }

$since = (Get-Date).AddDays(-30)
$crashEvents = Try-Run { @(Get-WinEvent -FilterHashtable @{ LogName = 'System'; Id = 41, 1001, 6008; StartTime = $since } -ErrorAction SilentlyContinue) }
$unexpected = @($crashEvents.Value | Where-Object { $_.Id -eq 41 }).Count
if ($unexpected -eq 0) { $unexpected = @($crashEvents.Value | Where-Object { $_.Id -eq 6008 }).Count }
if ($unexpected -gt 0) { Add-Flag "$unexpected unexpected shutdown(s) in the last 30 days (crash, power loss or forced power off)." }
$diskEvents = Try-Run { @(Get-WinEvent -FilterHashtable @{ LogName = 'System'; ProviderName = 'disk'; Id = 7, 11; StartTime = $since } -ErrorAction SilentlyContinue) }
if (@($diskEvents.Value).Count -gt 0) { Add-Flag "$(@($diskEvents.Value).Count) disk error event(s) in the last 30 days (bad blocks or controller errors)." }

# ---------- Backup, remote exposure, accounts, firmware ----------
# These only read. A fact that cannot be read says so in its section and raises no flag.
$backupFacts = New-Object System.Collections.Generic.List[string]
$fileHistory = Try-Run { @(Get-ChildItem -LiteralPath (Join-Path $env:LOCALAPPDATA 'Microsoft\Windows\FileHistory\Configuration') -Filter 'Config*.xml' -ErrorAction SilentlyContinue) }
$fileHistoryOn = $false
$backupAge = $null
if ($fileHistory.Note) {
    $backupFacts.Add("File History: $($fileHistory.Note)")
} elseif (@($fileHistory.Value).Count -eq 0) {
    $backupFacts.Add('File History: not set up')
} else {
    $fileHistoryOn = $true
    $backupFacts.Add('File History: set up')
    $lastBackup = Try-Run {
        $dates = foreach ($config in @($fileHistory.Value)) {
            $xml = Get-Content -LiteralPath $config.FullName -Raw
            foreach ($match in [regex]::Matches($xml, '<[A-Za-z]*(?:Backup|Protect|Success)[A-Za-z]*(?:Time|Date)[A-Za-z]*>([^<]+)<')) {
                $parsed = [datetime]::MinValue
                if ([datetime]::TryParse($match.Groups[1].Value, [ref]$parsed) -and $parsed.Year -gt 2000 -and $parsed -le (Get-Date)) { $parsed }
            }
        }
        $dates | Sort-Object -Descending | Select-Object -First 1
    }
    if ($lastBackup.Note) {
        $backupFacts.Add("Last File History backup: $($lastBackup.Note)")
    } elseif ($lastBackup.Value) {
        $backupAge = [int][math]::Floor(((Get-Date) - $lastBackup.Value).TotalDays)
        $backupFacts.Add("Last File History backup: $($lastBackup.Value.ToString('yyyy-MM-dd')) ($backupAge days ago)")
    } else {
        $backupFacts.Add('Last File History backup: unknown (no backup date found in its settings)')
    }
}

# Windows Backup and OneDrive folder backup both work by moving the folders into OneDrive.
$oneDriveRoots = @($env:OneDrive, $env:OneDriveConsumer, $env:OneDriveCommercial | Where-Object { $_ })
$oneDriveSaved = New-Object System.Collections.Generic.List[string]
foreach ($known in @(@{ Name = 'Desktop'; Id = 'Desktop' }, @{ Name = 'Documents'; Id = 'MyDocuments' }, @{ Name = 'Pictures'; Id = 'MyPictures' })) {
    $path = [Environment]::GetFolderPath($known.Id)
    foreach ($root in $oneDriveRoots) {
        if ($path -and ($path -eq $root -or $path.StartsWith("$root\", [StringComparison]::OrdinalIgnoreCase))) { $oneDriveSaved.Add($known.Name); break }
    }
}
$oneDriveOn = $oneDriveSaved.Count -gt 0
$backupFacts.Add("OneDrive signed in: $(if ($oneDriveRoots.Count -gt 0) { 'yes' } else { 'no' })")
$backupFacts.Add("Folders saved to OneDrive (Windows Backup): $(if ($oneDriveOn) { $oneDriveSaved -join ', ' } else { 'none' })")
$backupFacts.Add('OneDrive keeps these folders in sync, so it has no backup date. Other backup software cannot be detected by this scan.')

if (-not $fileHistory.Note -and -not $fileHistoryOn -and -not $oneDriveOn) {
    Add-Flag "No backup was found (File History is off and Desktop, Documents and Pictures are not saved to OneDrive)."
} elseif ($fileHistoryOn -and -not $oneDriveOn -and $null -ne $backupAge -and $backupAge -gt 30) {
    Add-Flag "The last backup was $backupAge days ago (File History)."
}

$remoteFacts = New-Object System.Collections.Generic.List[string]
$rdp = Try-Run { Get-RegValue 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server' 'fDenyTSConnections' }
if ($rdp.Note) {
    $remoteFacts.Add("Remote Desktop (RDP): $($rdp.Note)")
} elseif ($rdp.Value -eq 0) {
    $nla = Try-Run { Get-RegValue 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp' 'UserAuthentication' }
    $nlaText = if ($nla.Note) { $nla.Note } elseif ($nla.Value -eq 1) { 'yes' } else { 'no' }
    $remoteFacts.Add("Remote Desktop (RDP): ENABLED (network level authentication required: $nlaText)")
    Add-Flag 'Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.'
} else {
    $remoteFacts.Add('Remote Desktop (RDP): off')
}

$lanmanKey = 'HKLM:\SYSTEM\CurrentControlSet\Services\LanmanServer\Parameters'
$smb1 = if ($IsAdmin) {
    Try-Run { [int][bool](Get-SmbServerConfiguration).EnableSMB1Protocol }
} else {
    Try-Run {
        $value = (Get-ItemProperty -LiteralPath $lanmanKey -ErrorAction Stop).SMB1
        if ($null -eq $value) { throw 'administrator rights are needed to see whether SMBv1 is on' }
        $value
    }
}
if ($smb1.Note) {
    $remoteFacts.Add("SMBv1: $($smb1.Note)")
} elseif ($smb1.Value -eq 1) {
    $remoteFacts.Add('SMBv1: ENABLED')
    Add-Flag 'SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).'
} else {
    $remoteFacts.Add('SMBv1: off')
}

$autoLogon = Try-Run { (Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon' -ErrorAction Stop).AutoAdminLogon }
if ($autoLogon.Note) {
    $remoteFacts.Add("Automatic sign-in: $($autoLogon.Note)")
} elseif ("$($autoLogon.Value)" -eq '1') {
    $remoteFacts.Add('Automatic sign-in: ON (the stored password is not read)')
    Add-Flag 'Automatic sign-in is on, so anyone who starts this computer gets in without a password.'
} else {
    $remoteFacts.Add('Automatic sign-in: off')
}

$adminFacts = New-Object System.Collections.Generic.List[string]
$localUsers = Try-Run { @(Get-LocalUser) }
$adminGroup = Try-Run { @(Get-LocalGroupMember -SID 'S-1-5-32-544') }
if ($adminGroup.Note -or $localUsers.Note) {
    $adminFacts.Add("Members of the Administrators group: $(if ($adminGroup.Note) { $adminGroup.Note } else { $localUsers.Note })")
} else {
    $disabledSids = @($localUsers.Value | Where-Object { $_.Enabled -eq $false } | ForEach-Object { $_.SID.Value })
    $adminCount = @($adminGroup.Value | Where-Object { $disabledSids -notcontains $_.SID.Value }).Count
    $adminFacts.Add("Members of the Administrators group (not counting disabled accounts): $adminCount")
    if ($adminCount -ge 3) { Add-Flag "$adminCount accounts have administrator rights (see Administrator accounts section)." }
    $noPassword = @($localUsers.Value | Where-Object { $_.Enabled -and $_.PasswordRequired -eq $false })
    $adminFacts.Add("Enabled local accounts that do not need a password: $($noPassword.Count)")
    if ($noPassword.Count -gt 0) { Add-Flag "$($noPassword.Count) enabled account(s) are not required to have a password (see Administrator accounts section)." }
}

$biosFacts = New-Object System.Collections.Generic.List[string]
if ("$($cs.Manufacturer) $($cs.Model)" -match 'virtual|vmware|hyper-v|qemu|kvm|xen|parallels') {
    $biosFacts.Add('BIOS age: not checked (this is a virtual machine)')
} elseif ($bios.ReleaseDate) {
    $biosYears = [int][math]::Floor(((Get-Date) - $bios.ReleaseDate).TotalDays / 365.25)
    $biosFacts.Add("BIOS release date: $($bios.ReleaseDate.ToString('yyyy-MM-dd')) (about $biosYears full years ago)")
    if ($biosYears -ge 5) { Add-Flag "The BIOS (firmware) is $biosYears years old (released $($bios.ReleaseDate.ToString('yyyy-MM-dd')))." }
} else {
    $biosFacts.Add('BIOS release date: could not check (the firmware did not report one)')
}

# ---------- Sections ----------
Add-Section 'Hardware overview' {
    [pscustomobject]@{
        Manufacturer = $cs.Manufacturer; Model = $cs.Model; Family = $cs.SystemFamily
        SKU = $cs.SystemSKUNumber; Version = $product.Version; Chassis = ($chassis -join ',')
        BIOS = "$($bios.Manufacturer) $($bios.SMBIOSBIOSVersion)"; BIOSDate = $bios.ReleaseDate
        Board = "$($board.Manufacturer) $($board.Product)"
    } | Format-List
}
Add-Section 'Identity as reported by firmware (before placeholders are removed)' {
    "BIOS serial:        $(One-Line $bios.SerialNumber)"
    "Product identifier: $(One-Line $product.IdentifyingNumber)"
    "System UUID:        $(One-Line $product.UUID)"
    "Baseboard serial:   $(One-Line $board.SerialNumber)"
    ''
    'The header only uses values that look real. Blank means the firmware gave a placeholder.'
}
Add-Section 'Windows version' {
    "$osLine"
    "Install date: $($os.InstallDate)"
    "Architecture: $($os.OSArchitecture)"
    "Edition: $(Get-RegValue $currentVersion 'EditionID')"
    "Last boot: $($os.LastBootUpTime)"
    'Uptime can be inflated by Fast Startup, which keeps the kernel running across shutdowns.'
}
Add-Section 'CPU and memory' {
    $cpus | Select-Object Name, NumberOfCores, NumberOfLogicalProcessors, MaxClockSpeed, LoadPercentage | Format-List
    Get-CimInstance Win32_PhysicalMemory | Select-Object BankLabel, @{ N = 'SizeGB'; E = { [math]::Round($_.Capacity / 1GB) } }, Speed, Manufacturer, PartNumber | Format-Table -AutoSize
    "Total: $ramGb GB, available: $availablePct% of visible memory"
}
Add-Section 'Battery and power' {
    if ($batteries.Count -eq 0) { 'No battery found.'; return }
    $batteries | Select-Object Name, EstimatedChargeRemaining, BatteryStatus, DesignVoltage | Format-List
    "Design capacity:      $(if ($batteryDesign) { "$batteryDesign mWh" } else { 'unavailable' })"
    "Full-charge capacity: $(if ($batteryFull) { "$batteryFull mWh" } else { 'unavailable' })"
    "Health:               $(if ($null -ne $batteryPct) { "$batteryPct% of design" } else { 'unavailable' })"
    "Cycle count:          $(if ($null -ne $batteryCycles) { $batteryCycles } else { 'unavailable' })"
    if ($batteryNote) { "Battery report: $batteryNote" }
    ''
    (powercfg /list) -join "`n"
}
Add-Section 'Storage (physical disks)' {
    if ($physical.Value) { $physical.Value | Format-Table -AutoSize } elseif ($diskFallback.Value) { $diskFallback.Value | Format-Table -AutoSize } else { $physical.Note }
}
Add-Section 'Storage (volumes and free space)' {
    $volumes | Select-Object DeviceID, VolumeName, FileSystem, @{ N = 'SizeGB'; E = { [math]::Round($_.Size / 1GB) } }, @{ N = 'FreeGB'; E = { [math]::Round($_.FreeSpace / 1GB) } }, @{ N = 'Free%'; E = { if ($_.Size) { [math]::Floor(100 * $_.FreeSpace / $_.Size) } } } | Format-Table -AutoSize
}
Add-Section 'Disk wear and errors (SMART counters, needs admin)' {
    if ($reliability.Value) { $reliability.Value | Format-Table -AutoSize } else { $reliability.Note }
}
Add-Section 'Top processes by CPU (5 second sample, grouped by name)' {
    $cpuCount = [math]::Max(1, [int]$threads)
    $first = @{}
    foreach ($p in Get-Process) { $first[$p.Id] = [double]$p.CPU }
    Start-Sleep -Seconds 5
    $now = Get-Process
    $now | Group-Object ProcessName | ForEach-Object {
        $used = 0.0
        foreach ($p in $_.Group) { if ($first.ContainsKey($p.Id)) { $used += [double]$p.CPU - $first[$p.Id] } }
        [pscustomobject]@{ Name = $_.Name; 'CPU%' = [math]::Round(100 * $used / (5 * $cpuCount), 1); Count = $_.Count; MemoryMB = [math]::Round(($_.Group | Measure-Object WorkingSet64 -Sum).Sum / 1MB) }
    } | Sort-Object 'CPU%' -Descending | Select-Object -First 12 | Format-Table -AutoSize
}
Add-Section 'Top processes by memory (grouped by name)' {
    Get-Process | Group-Object ProcessName | ForEach-Object {
        [pscustomobject]@{ Name = $_.Name; MemoryMB = [math]::Round(($_.Group | Measure-Object WorkingSet64 -Sum).Sum / 1MB); Count = $_.Count }
    } | Sort-Object MemoryMB -Descending | Select-Object -First 12 | Format-Table -AutoSize
}
Add-Section 'Graphics' {
    Get-CimInstance Win32_VideoController | Select-Object Name, DriverVersion, @{ N = 'VRAM_GB'; E = { [math]::Round($_.AdapterRAM / 1GB, 1) } }, CurrentHorizontalResolution, CurrentVerticalResolution | Format-List
}
Add-Section 'Network adapters' { Get-NetAdapter | Select-Object Name, InterfaceDescription, Status, LinkSpeed | Format-Table -AutoSize }
Add-Section 'Bluetooth, cameras and audio' {
    $devices = Get-CimInstance Win32_PnPEntity | Where-Object { $_.PNPClass -in 'Bluetooth', 'Camera', 'Image', 'AudioEndpoint' }
    $devices | Sort-Object PNPClass, Name | Select-Object PNPClass, Status, Name | Format-Table -AutoSize
}

Add-Section 'Security: Secure Boot, TPM, BitLocker' {
    "Secure Boot: $secureBoot"
    "TPM:         $tpmSummary"
    "BitLocker:   $bitLocker ($systemDrive)"
    "Run as administrator: $IsAdmin"
}
Add-Section 'Security: Defender' {
    if ($defender.Value) {
        $defender.Value | Select-Object AMServiceEnabled, AntivirusEnabled, RealTimeProtectionEnabled, IsTamperProtected, AntivirusSignatureAge, AntivirusSignatureLastUpdated, AMProductVersion | Format-List
    } else { $defender.Note }
    if ($thirdPartyAv.Count -gt 0) { "Other antivirus registered: $($thirdPartyAv -join ', ')" }
}
Add-Section 'Security: firewall' { if ($firewall.Value) { $firewall.Value | Format-Table -AutoSize } else { $firewall.Note } }
Add-Section 'Activation' {
    $activation
    ''
    "Edition: $(Get-RegValue $currentVersion 'EditionID')"
}
Add-Section 'Domain, Entra ID and MDM' {
    "Domain joined: $($cs.PartOfDomain) ($(One-Line $cs.Domain))"
    if ($dsreg.Value) { @($dsreg.Value) | Where-Object { $_ -match '^\s*(AzureAdJoined|EnterpriseJoined|DomainJoined|TenantName|MdmUrl)\s*:' } } else { $dsreg.Note }
}
Add-Section 'Security and remote access agents running' {
    if (@($securityAgents.Value).Count -gt 0) { @($securityAgents.Value) -join "`n" } else { '(none found)' }
}
Add-Section 'User accounts' {
    Get-LocalUser | Select-Object Name, Enabled, LastLogon, PasswordRequired | Format-Table -AutoSize
    'Administrators group:'
    Get-LocalGroupMember -Group 'Administrators' | Select-Object Name, ObjectClass | Format-Table -AutoSize
}

Add-Section 'Backup status' { $backupFacts }
Add-Section 'Remote exposure' { $remoteFacts }
Add-Section 'Administrator accounts' { $adminFacts }
Add-Section 'BIOS and firmware age' { $biosFacts }

Add-Section 'Windows Update' {
    "Newest installed update: $(if ($lastInstall) { "$($lastInstall.ToString('yyyy-MM-dd')) ($installAge days ago)" } else { 'unknown (no install date could be read)' })"
    "Restart pending: $(if ($rebootReasons.Count -gt 0) { $rebootReasons -join '; ' } else { 'no' })"
    "Pending file rename operations (often harmless): $(if ($renames.Value) { 'yes' } else { 'no' })"
    ''
    'Pending updates:'
    if ($pendingUpdates.Value) { if (@($pendingUpdates.Value).Count -gt 0) { @($pendingUpdates.Value) } else { '(none)' } } else { $pendingUpdates.Note }
    ''
    'Recently installed:'
    if ($hotfixes.Value) { @($hotfixes.Value) | Select-Object -First 8 HotFixID, Description, InstalledOn | Format-Table -AutoSize } else { $hotfixes.Note }
}
Add-Section 'Installed apps' {
    $paths = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*'
    Get-ItemProperty -Path $paths -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -and -not $_.SystemComponent } |
        Sort-Object DisplayName -Unique | Select-Object -First 300 DisplayName, DisplayVersion, Publisher | Format-Table -AutoSize
}
Add-Section 'Startup programs' {
    "Count: $(@($startup.Value).Count)"
    if ($startup.Value) { $startup.Value | Select-Object Name, Command, Location | Format-Table -AutoSize -Wrap } else { $startup.Note }
}
Add-Section 'Devices with problems (Device Manager)' {
    if ($badDevices.Value -and @($badDevices.Value).Count -gt 0) { $badDevices.Value | Select-Object Name, DeviceID, ConfigManagerErrorCode | Format-Table -AutoSize -Wrap } elseif ($badDevices.Note) { $badDevices.Note } else { '(none)' }
}
Add-Section 'Listening network ports' {
    $names = @{}
    foreach ($p in Get-Process) { $names[$p.Id] = $p.ProcessName }
    Get-NetTCPConnection -State Listen | Sort-Object LocalPort | Select-Object -First 40 LocalAddress, LocalPort, @{ N = 'Process'; E = { $names[[int]$_.OwningProcess] } } | Format-Table -AutoSize
}
Add-Section 'Hosts file (non-default lines)' {
    Get-Content -LiteralPath "$env:SystemRoot\System32\drivers\etc\hosts" | Where-Object { $_ -notmatch '^\s*#' -and $_.Trim() }
}
Add-Section 'Crashes and unexpected shutdowns (last 30 days)' {
    if ($crashEvents.Value -and @($crashEvents.Value).Count -gt 0) {
        $crashEvents.Value | Select-Object -First 15 TimeCreated, Id, ProviderName | Format-Table -AutoSize
    } elseif ($crashEvents.Note) { $crashEvents.Note } else { '(none)' }
}
Add-Section 'Disk error events (last 30 days)' {
    if ($diskEvents.Value -and @($diskEvents.Value).Count -gt 0) {
        $diskEvents.Value | Select-Object -First 15 TimeCreated, Id, Message | Format-Table -AutoSize -Wrap
    } elseif ($diskEvents.Note) { $diskEvents.Note } else { '(none)' }
}
Add-Section 'Locale and time' {
    Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz'
    (Get-TimeZone).Id
    (Get-Culture).Name
}

# ---------- Assemble ----------
$batteryLine = if ($batteries.Count -eq 0) { 'none' } else {
    $cyclesText = if ($null -ne $batteryCycles) { $batteryCycles } else { 'n/a' }
    $healthText = if ($null -ne $batteryPct) { "$batteryPct% of design capacity" } else { 'n/a' }
    "$cyclesText cycles, $healthText"
}
$freeText = if ($null -ne $freePct) { "$freePct%" } else { 'n/a' }

$lines = New-Object System.Collections.Generic.List[string]
$lines.Add("# Windows checkup: $HostName")
$lines.Add('')
$lines.Add('This report comes from one scan that ran once, only read information, and installed and changed nothing on this PC.')
$lines.Add('')
$lines.Add('- Format: 1')
$lines.Add('- OS: Windows')
$lines.Add("- Device type: $deviceType")
$lines.Add("- Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm')")
$lines.Add("- Serial: $serial")
$lines.Add("- Hardware UUID: $uuid")
$lines.Add("- Model: $model")
$lines.Add("- Chip/CPU: $cpuName ($cores cores, $threads threads)")
$lines.Add("- Memory: $ramGb GB")
$lines.Add("- Windows: $osLine")
$lines.Add("- Battery: $batteryLine")
$lines.Add("- Free disk: $freeText")
$lines.Add("- Secure Boot: $secureBoot")
$lines.Add("- BitLocker: $bitLocker")
$lines.Add("- Run as administrator: $(if ($IsAdmin) { 'yes' } else { 'no, some sections say unavailable' })")
$lines.Add('')
$lines.Add('## Things to look at')
$lines.Add('')
if ($Flags.Count -gt 0) { foreach ($flag in $Flags) { $lines.Add("- $flag") } } else { $lines.Add('- Nothing flagged automatically.') }
foreach ($section in $Body) { $lines.Add($section) }
$lines.Add(@'

## Manual checklist (cannot be done by script)

Hardware
- [ ] Serial number matches the sticker on the case or the box.
- [ ] Keyboard (every key), touchpad and click, speakers, microphone, webcam.
- [ ] Every port: charging, USB, video out, headphone jack, card reader.
- [ ] Screen: dead pixels, bright spots, burn-in (view solid white, black and red).
- [ ] Case: dents, swollen battery (touchpad lifting or case bulging), loose hinge, liquid damage.
- [ ] Stress test: run a video or heavy task for 10 minutes. Watch for fan noise, heat, throttling or shutdowns.
- [ ] No BIOS or UEFI password is set (press F2, Del or F10 at startup).

Handover and cleanup
- [ ] Windows is activated with a genuine licence (Settings > System > Activation).
- [ ] BitLocker recovery key is known, or the disk is decrypted.
- [ ] No old user accounts, saved passwords, mail accounts or personal files left behind.
- [ ] Backup exists before changing anything.
- [ ] Paid apps tied to the previous owner (Office, Adobe) are reassigned.
- [ ] For a clean start: Settings > System > Recovery > Reset this PC > Remove everything.
'@)

$text = ($lines -join "`n") -replace "`r`n", "`n" -replace "`r", "`n"
[IO.File]::WriteAllText($Out, $text + "`n", (New-Object System.Text.UTF8Encoding($false)))

Write-Host ''
Write-Host 'Done. Report saved to:'
Write-Host "  $Out"
Write-Host ''
Invoke-ReportUpload ($text + "`n") $found $foundCode $Url
Write-Host ''
Write-Host 'Optional: for the sections that need admin rights (TPM details, BitLocker, disk wear), run again from an elevated PowerShell.'
