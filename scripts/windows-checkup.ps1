# Read-only Windows checkup. Writes a Markdown report to the Desktop.
# The scan runs once, only reads information, and installs and changes nothing: it changes no
# setting, starts no service, schedules nothing and leaves nothing running afterwards.
# Usage: powershell -ExecutionPolicy Bypass -File windows-checkup.ps1 [-Token <token>] [-Code <code>] [-Url <url>]
# Works on Windows PowerShell 5.1 and PowerShell 7. Needs no admin rights; sections that
# do need them say "unavailable (needs admin)". Keep this file ASCII only: 5.1 reads a
# file without a byte order mark as ANSI.
#
# The storage step measures folder sizes and groups the user folder by kind of file, for at most
# 150 seconds. It stores sizes and counts, plus the names and sizes of the biggest folders in your
# user profile (8 folders, and 5 inside each of the 2 biggest), never a file name or a full path.
# It follows no junctions or links and leaves other disks alone. If the time runs out the report
# says the numbers are partial.
#
# The space step looks, for at most 60 seconds, at a few fixed places where space can be got back:
# the Recycle Bin, the Temp folders, downloaded Windows updates, installers in Downloads and
# Windows.old if it exists. It also notes the 10 biggest files of 50 MB or more in your user
# profile (file name, size and the name of the folder above it, never a full path). It deletes
# nothing.
#
# The apps step looks, for at most 45 seconds, at the programs in the Uninstall registry keys that were
# not used for 12 months or more. It stores the name, the size and the date of last use of at most 15
# programs of 100 MB or more, never a publisher or an install path, and leaves out Microsoft and
# Windows components and drivers. Dates of last use come from Prefetch only when that folder can be
# read without admin rights. The backup step reads when File History last ran, whether Desktop,
# Documents and Pictures live in OneDrive and the date of the newest restore point. Neither step
# changes anything.
#
# The health and security step reads the state of each drive (health, wear, temperature, error counts)
# and a list of security settings (Defender, other antivirus names, BitLocker, firewall, Remote Desktop,
# SMBv1, how many accounts have administrator rights, UAC). It stores no serial numbers and no account
# names, and spends at most 90 seconds. What cannot be read without admin rights says could not check.
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
# The app swaps this marker for the version of this file when it serves the download.
$ScriptVersion = '__PCC_SCRIPT_VERSION__'

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

function Test-StorageFixedPath([string]$Path) {
    if ([System.IO.Path]::DirectorySeparatorChar -ne '\') { return $true }
    try {
        $root = [System.IO.Path]::GetPathRoot($Path)
        if (-not $root -or $root.StartsWith('\\')) { return $false }
        return (New-Object System.IO.DriveInfo($root)).DriveType -eq [System.IO.DriveType]::Fixed
    } catch {
        return $false
    }
}

# Adds up the file sizes under a folder until $StopAtMs on $Clock. It never enters a junction or
# symbolic link, skips files that only exist in the cloud, and with $Stats also counts the files by
# kind and extension. File names are looked at in memory only and never kept. With $Tally, a folder
# inside the user profile also adds its bytes to the folder directly inside the profile (Tally.One)
# and to the folder inside that one (Tally.Two, keyed "parent\child"); only those two names are kept.
function Measure-StorageTree([string]$Path, $Clock, [long]$StopAtMs, $Stats, $Skip, $Tally = $null) {
    $bytes = [long]0
    $capped = $false
    $directory = [int][System.IO.FileAttributes]::Directory
    $reparse = [int][System.IO.FileAttributes]::ReparsePoint
    $cloudOnly = 0x400000 -bor 0x40000 -bor 0x1000
    $stack = New-Object 'System.Collections.Generic.Stack[object[]]'
    $start = New-Object System.IO.DirectoryInfo($Path)
    if ([int]$start.Attributes -band $reparse) { return [pscustomobject]@{ Bytes = $bytes; Capped = $false } }
    $one = $null
    $two = $null
    if ($Tally) {
        $separators = [char[]]@('\', '/')
        $full = [System.IO.Path]::GetFullPath($Path).TrimEnd($separators)
        $inside = $Tally.Profile + [System.IO.Path]::DirectorySeparatorChar
        if ($full.StartsWith($inside, [System.StringComparison]::OrdinalIgnoreCase)) {
            $names = $full.Substring($inside.Length).Split($separators, [System.StringSplitOptions]::RemoveEmptyEntries)
            if ($names.Count -gt 0) { $one = $names[0] }
            if ($names.Count -gt 1) { $two = $names[1] }
        } elseif ($full -ine $Tally.Profile) {
            $Tally = $null
        }
    }
    $stack.Push([object[]]@($start, $one, $two))
    while ($stack.Count -gt 0) {
        if ($Clock.ElapsedMilliseconds -ge $StopAtMs) { $capped = $true; break }
        $frame = $stack.Pop()
        $current = $frame[0]
        $one = $frame[1]
        $two = $frame[2]
        $here = [long]0
        try { $entries = $current.GetFileSystemInfos() } catch { continue }
        foreach ($entry in $entries) {
            try {
                $attributes = [int]$entry.Attributes
                if ($attributes -band $directory) {
                    if (($attributes -band $reparse) -or ($Skip -and $Skip.Contains($entry.FullName))) { continue }
                    $childOne = $one
                    $childTwo = $two
                    if ($Tally) {
                        if ($null -eq $one) { $childOne = $entry.Name } elseif ($null -eq $two) { $childTwo = $entry.Name }
                    }
                    $stack.Push([object[]]@($entry, $childOne, $childTwo))
                    continue
                }
                if ($attributes -band $cloudOnly) { continue }
                $length = [long]$entry.Length
                $bytes += $length
                $here += $length
                if ($Stats) {
                    $name = $entry.Name
                    $dot = $name.LastIndexOf('.')
                    $extension = ''
                    if ($dot -gt 0 -and $dot -lt $name.Length - 1) { $extension = $name.Substring($dot + 1).ToLowerInvariant() }
                    $kind = 'Other'
                    if ($extension -ne '' -and $Stats.Group.ContainsKey($extension)) { $kind = $Stats.Group[$extension] }
                    $slot = $Stats.Kind[$kind]
                    $slot[0] += $length
                    $slot[1] += 1
                    if ($extension -eq '') {
                        $slot = $Stats.Bare
                        $slot[0] += $length
                        $slot[1] += 1
                    } else {
                        $slot = $Stats.Ext[$extension]
                        if ($null -eq $slot) {
                            if ($extension.Length -le 10 -and $extension -cmatch '^[a-z0-9]+$') { $slot = @([long]0, [long]0) } else { $slot = $false }
                            $Stats.Ext[$extension] = $slot
                        }
                        if ($slot -is [array]) {
                            $slot[0] += $length
                            $slot[1] += 1
                        }
                    }
                }
            } catch {
                continue
            }
        }
        if ($Tally -and $here -gt 0 -and $null -ne $one) {
            $Tally.One[$one] = [long]$Tally.One[$one] + $here
            if ($null -ne $two) {
                $key = $one + '\' + $two
                $Tally.Two[$key] = [long]$Tally.Two[$key] + $here
            }
        }
    }
    return [pscustomobject]@{ Bytes = $bytes; Capped = $capped }
}

# A folder name as the report shows it: no control characters, pipes or slashes, spaces collapsed,
# at most 60 characters. Returns $null when nothing usable is left.
function Get-StorageFolderLabel([string]$Name, [int]$Limit = 60) {
    $label = $Name -replace '[\t\r\n\f\v]', ' '
    $label = $label -replace '[\x00-\x1F\x7F|/\\]', ''
    $label = ($label -replace ' {2,}', ' ').Trim()
    if ($label.Length -gt $Limit) {
        $label = $label.Substring(0, $Limit)
        if ([char]::IsHighSurrogate($label[$Limit - 1])) { $label = $label.Substring(0, $Limit - 1) }
        $label = $label.TrimEnd()
    }
    if ($label -eq '' -or $label -eq '.' -or $label -eq '..') { return $null }
    return $label
}

# The biggest names of a tally as label and KB: at most $Limit, biggest first, a label that is
# already taken skipped (the bigger folder came first), folders under 1 KB left out.
function Get-StorageBiggest($Sizes, [int]$Limit) {
    $picked = New-Object System.Collections.Generic.List[object]
    $seen = New-Object 'System.Collections.Generic.HashSet[string]'
    $ordered = @($Sizes.GetEnumerator() | Sort-Object @{ Expression = { $_.Value }; Descending = $true }, @{ Expression = { $_.Key }; Descending = $false })
    foreach ($item in $ordered) {
        if ($picked.Count -ge $Limit) { break }
        $kb = [long][math]::Round([long]$item.Value / 1024.0)
        $label = Get-StorageFolderLabel $item.Key
        if ($kb -lt 1 -or $null -eq $label -or -not $seen.Add($label)) { continue }
        $picked.Add([pscustomobject]@{ Raw = $item.Key; Label = $label; Kb = $kb })
    }
    return $picked.ToArray()
}

# home|Name|KB for the 8 biggest folders directly inside the user profile, then sub|Parent|Name|KB
# for the 5 biggest inside each of the 2 biggest of those.
function Get-StorageHomeLines($Tally) {
    $lines = New-Object System.Collections.Generic.List[string]
    $top = @(Get-StorageBiggest $Tally.One 8)
    foreach ($folder in $top) { $lines.Add("home|$($folder.Label)|$($folder.Kb)") }
    foreach ($folder in @($top | Select-Object -First 2)) {
        $inside = @{}
        foreach ($item in $Tally.Two.GetEnumerator()) {
            if ($item.Key.StartsWith($folder.Raw + '\', [System.StringComparison]::OrdinalIgnoreCase)) { $inside[$item.Key.Substring($folder.Raw.Length + 1)] = $item.Value }
        }
        foreach ($child in @(Get-StorageBiggest $inside 5)) { $lines.Add("sub|$($folder.Label)|$($child.Label)|$($child.Kb)") }
    }
    return $lines.ToArray()
}

# How much of the time left in a phase one bucket may use: its weight's share of the weight still to come.
function Get-StorageSlice($Clock, [long]$EndMs, [double]$Weight, [double]$WeightLeft) {
    $room = $EndMs - $Clock.ElapsedMilliseconds
    if ($room -le 0 -or $WeightLeft -le 0) { return [long]0 }
    return [long]($room * $Weight / $WeightLeft)
}

# Position of a well-known folder name in a list, ignoring case, or -1.
function Get-StorageKnownIndex($Known, [string]$Name) {
    for ($i = 0; $i -lt $Known.Count; $i++) {
        if ($Known[$i] -ieq $Name) { return $i }
    }
    return -1
}

# Measures disk usage once, read only, and never longer than the cap (150 seconds by default).
# The top level of the drive comes first, then the user folder, and each bucket gets its share of
# the clock and hands back what it did not use. A bucket that runs out of time is listed as missing
# instead of with a number that would be too small. Returns the lines of the "Storage breakdown"
# section; progress goes to the screen.
function Get-StorageBreakdown {
    $StorageCapSeconds = 150
    if ($env:PCC_STORAGE_CAP -match '^[1-9][0-9]{0,4}$') { $StorageCapSeconds = [int]$env:PCC_STORAGE_CAP }
    $clock = [System.Diagnostics.Stopwatch]::StartNew()
    $capMs = [long]$StorageCapSeconds * 1000
    $capped = $false
    $profilePath = $env:PCC_STORAGE_PROFILE
    $testing = [bool]$profilePath
    if (-not $profilePath) { $profilePath = $env:USERPROFILE }
    $rootPath = $env:PCC_STORAGE_ROOT
    if (-not $rootPath) { $rootPath = $env:SystemDrive + '\' }
    $separators = [char[]]@('\', '/')
    $special = @{ Desktop = 'Desktop'; Documents = 'MyDocuments'; Pictures = 'MyPictures'; Videos = 'MyVideos'; Music = 'MyMusic' }
    $stats = @{ Group = @{}; Kind = @{}; Ext = @{}; Bare = @([long]0, [long]0) }
    $groups = [ordered]@{
        Photos    = 'jpg jpeg png gif heic heif bmp tif tiff webp raw cr2 nef arw dng'
        Videos    = 'mp4 mov avi mkv wmv m4v webm flv mpg mpeg 3gp'
        Music     = 'mp3 m4a wav flac aac ogg wma aiff aif opus'
        Documents = 'pdf doc docx xls xlsx ppt pptx txt rtf odt ods odp csv pages numbers key md epub'
        Archives  = 'zip rar 7z tar gz tgz bz2 xz iso zst cab'
        Apps      = 'exe msi dmg pkg app ipa apk appx msix'
        Other     = ''
    }
    foreach ($kind in $groups.Keys) {
        $stats.Kind[$kind] = @([long]0, [long]0)
        foreach ($extension in $groups[$kind].Split(' ', [System.StringSplitOptions]::RemoveEmptyEntries)) { $stats.Group[$extension] = $kind }
    }

    Write-Host "Measuring storage (the drive, then your user folder, then file types), stopping after $StorageCapSeconds seconds at the latest..."
    $profileFull = ''
    if (Test-Path -LiteralPath $profilePath -PathType Container) { $profileFull = [System.IO.Path]::GetFullPath($profilePath).TrimEnd($separators) }
    $tally = $null
    if ($profileFull -ne '') { $tally = @{ Profile = $profileFull; One = @{}; Two = @{} } }

    Write-Host '  Measuring the biggest folders on the drive...'
    $known = @('Users', 'Windows', 'Program Files', 'Program Files (x86)', 'ProgramData')
    $knownWeights = @(10, 6, 5, 4, 3)
    $tops = @()
    if ((Test-Path -LiteralPath $rootPath -PathType Container) -and (Test-StorageFixedPath $rootPath)) {
        $tops = @((New-Object System.IO.DirectoryInfo($rootPath)).GetDirectories() | Where-Object {
            -not ([int]$_.Attributes -band [int][System.IO.FileAttributes]::ReparsePoint) -and $_.Name -ne 'System Volume Information' -and $_.Name -ne '$Recycle.Bin'
        } | Sort-Object @{ Expression = { Get-StorageKnownIndex $known $_.Name } }, Name)
    }
    $phaseEnd = [long][math]::Max(1000, $capMs * 35 / 100)
    $weightLeft = [double]0
    foreach ($top in $tops) {
        $at = Get-StorageKnownIndex $known $top.Name
        if ($at -ge 0) { $weightLeft += $knownWeights[$at] } else { $weightLeft += 1 }
    }
    $drive = New-Object System.Collections.Generic.List[object]
    foreach ($top in $tops) {
        $at = Get-StorageKnownIndex $known $top.Name
        $weight = [double]1
        if ($at -ge 0) { $weight = [double]$knownWeights[$at] }
        $slice = Get-StorageSlice $clock $phaseEnd $weight $weightLeft
        $weightLeft -= $weight
        $holds = $false
        $skip = $null
        if ($profileFull -ne '' -and $profileFull.StartsWith($top.FullName.TrimEnd($separators) + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
            $holds = $true
            $skip = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
            [void]$skip.Add($profileFull)
        }
        $clean = $top.Name -replace '[^A-Za-z0-9 ._()+-]', '_'
        $entry = @{ Name = $clean.Substring(0, [math]::Min(40, $clean.Length)); Raw = $top.Name; Bytes = [long]0; Done = $false; Holds = $holds }
        if ($slice -gt 0) {
            $result = Measure-StorageTree $top.FullName $clock ($clock.ElapsedMilliseconds + $slice) $null $skip
            $entry.Bytes = $result.Bytes
            $entry.Done = -not $result.Capped
        }
        if (-not $entry.Done) { $capped = $true }
        $drive.Add($entry)
    }

    Write-Host '  Measuring your user folder and the kinds of files in it...'
    $appData = Join-Path $profilePath 'AppData'
    $local = Join-Path $appData 'Local'
    $roaming = Join-Path $appData 'Roaming'
    $temp = Join-Path $local 'Temp'
    $plan = New-Object System.Collections.Generic.List[object]
    $paths = @{ AppData = $appData }
    $inside = $profileFull + [System.IO.Path]::DirectorySeparatorChar
    $apart = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
    $order = @{ Desktop = 1; Music = 2; Pictures = 3; Videos = 4; Downloads = 5; Documents = 10 }
    $weights = @{ Desktop = 1; Music = 1; Pictures = 2; Videos = 2; Downloads = 2; Documents = 3 }
    foreach ($name in 'Downloads', 'Documents', 'Desktop', 'Pictures', 'Videos', 'Music') {
        $path = Join-Path $profilePath $name
        if (-not $testing -and $special.ContainsKey($name)) {
            $found = [Environment]::GetFolderPath($special[$name])
            if ($found) { $path = $found }
        }
        $paths[$name] = $path
        $plan.Add(@{ Key = $name; Path = $path; Skip = @(); Order = $order[$name]; Weight = $weights[$name] })
    }
    $plan.Add(@{ Key = 'Temp'; Path = $temp; Skip = @(); Order = 7; Weight = 1 })
    $plan.Add(@{ Key = 'Local'; Path = $local; Skip = @($temp); Order = 11; Weight = 5 })
    $plan.Add(@{ Key = 'Roaming'; Path = $roaming; Skip = @(); Order = 6; Weight = 2 })
    $plan.Add(@{ Key = 'AppDataRest'; Path = $appData; Skip = @($local, $roaming); Order = 8; Weight = 1 })
    foreach ($item in $plan) {
        if (@('Temp', 'Local', 'Roaming', 'AppDataRest') -notcontains $item.Key) { [void]$apart.Add([System.IO.Path]::GetFullPath($item.Path).TrimEnd($separators)) }
    }
    [void]$apart.Add([System.IO.Path]::GetFullPath($appData).TrimEnd($separators))
    $plan.Add(@{ Key = 'Rest'; Path = $profilePath; Skip = @(); Order = 9; Weight = 2 })

    $present = @($plan | Where-Object { (Test-Path -LiteralPath $_.Path -PathType Container) -and (Test-StorageFixedPath $_.Path) } | Sort-Object { $_.Order })
    $got = @{}
    $weightLeft = [double]0
    foreach ($item in $present) { $weightLeft += $item.Weight }
    foreach ($item in $present) {
        $slice = Get-StorageSlice $clock $capMs $item.Weight $weightLeft
        $weightLeft -= $item.Weight
        $skip = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
        foreach ($path in $item.Skip) { [void]$skip.Add([System.IO.Path]::GetFullPath($path).TrimEnd($separators)) }
        if ($item.Key -eq 'Rest') { foreach ($path in $apart) { [void]$skip.Add($path) } }
        $got[$item.Key] = $null
        if ($slice -gt 0) {
            $result = Measure-StorageTree $item.Path $clock ($clock.ElapsedMilliseconds + $slice) $stats $skip $tally
            if (-not $result.Capped) { $got[$item.Key] = $result.Bytes }
        }
        if ($null -eq $got[$item.Key]) { $capped = $true }
    }

    $absent = New-Object System.Collections.Generic.List[string]
    $homeTotal = $null
    if ($present.Count -gt 0 -and $present.Count -eq @($present | Where-Object { $null -ne $got[$_.Key] }).Count) {
        $homeTotal = [long]0
        foreach ($item in $present) {
            $full = [System.IO.Path]::GetFullPath($item.Path).TrimEnd($separators)
            if ($full -ieq $profileFull -or $full.StartsWith($inside, [System.StringComparison]::OrdinalIgnoreCase)) { $homeTotal += $got[$item.Key] }
        }
    }
    $folderBytes = @{}
    foreach ($name in 'Downloads', 'Documents', 'Desktop', 'Pictures', 'Videos', 'Music') { $folderBytes[$name] = $got[$name] }
    $appParts = @($present | Where-Object { @('Temp', 'Local', 'Roaming', 'AppDataRest') -contains $_.Key })
    $appBytes = $null
    if ($appParts.Count -gt 0 -and $appParts.Count -eq @($appParts | Where-Object { $null -ne $got[$_.Key] }).Count) {
        $appBytes = [long]0
        foreach ($item in $appParts) { $appBytes += $got[$item.Key] }
    }
    $folderBytes['AppData'] = $appBytes
    $libraryBytes = @{ Temp = $got['Temp']; Roaming = $got['Roaming']; Local = $null }
    if ($null -ne $got['Local'] -and ($null -ne $got['Temp'] -or -not $got.ContainsKey('Temp'))) { $libraryBytes['Local'] = [long]$got['Local'] + [long]$got['Temp'] }

    $scan = 'complete'
    if ($capped) {
        $scan = "partial (time limit of $StorageCapSeconds seconds reached)"
        Write-Host '  The time limit was reached, so the storage numbers are partial.'
    }
    $output = New-Object System.Collections.Generic.List[string]
    $output.Add("scan: $scan")
    $output.Add("seconds: $([int][math]::Floor($clock.Elapsed.TotalSeconds))")
    $output.Add("limit: $StorageCapSeconds")
    $output.Add('format: 2')

    $rows = New-Object System.Collections.Generic.List[object]
    $total = [long]0
    foreach ($entry in $drive) {
        $bytes = $entry.Bytes
        $done = $entry.Done
        if ($entry.Holds) {
            $done = $done -and $null -ne $homeTotal
            if ($done) { $bytes += $homeTotal }
        }
        if ($done) {
            $total += $bytes
            $rows.Add([pscustomobject]@{ Name = $entry.Name; Bytes = $bytes })
        } elseif ((Get-StorageKnownIndex $known $entry.Raw) -ge 0) {
            $absent.Add("missing|root/$($entry.Raw)")
        }
    }
    foreach ($item in ($rows | Sort-Object @{ Expression = { $_.Bytes }; Descending = $true }, Name | Select-Object -First 6)) { $output.Add("root|$($item.Name)|$([long][math]::Round($item.Bytes / 1024.0))") }
    if ($tops.Count -gt 0) { $output.Add("roots_total|$([long][math]::Round($total / 1024.0))") }
    foreach ($line in $absent) { $output.Add($line) }

    if ($null -ne $homeTotal) { $output.Add("home|$([long][math]::Round($homeTotal / 1024.0))") }
    if ($tally) { foreach ($line in (Get-StorageHomeLines $tally)) { $output.Add($line) } }
    foreach ($name in 'Downloads', 'Documents', 'Desktop', 'Pictures', 'Videos', 'Music', 'AppData') {
        $exists = Test-Path -LiteralPath $paths[$name] -PathType Container
        if ($null -ne $folderBytes[$name]) {
            $output.Add("folder|$name|$([long][math]::Round($folderBytes[$name] / 1024.0))")
        } elseif ($exists) {
            $output.Add("missing|home/$name")
        }
    }
    foreach ($name in 'Local', 'Roaming', 'Temp') {
        if ($null -ne $libraryBytes[$name]) {
            $output.Add("library|$name|$([long][math]::Round($libraryBytes[$name] / 1024.0))")
        } elseif ($got.ContainsKey($name)) {
            $output.Add("missing|library/$name")
        }
    }
    foreach ($kind in $groups.Keys) {
        if ($stats.Kind[$kind][1] -gt 0) { $output.Add("type|$kind|$([long][math]::Round($stats.Kind[$kind][0] / 1024.0))|$($stats.Kind[$kind][1])") }
    }
    if ($stats.Bare[1] -gt 0) { $output.Add("noext|$([long][math]::Round($stats.Bare[0] / 1024.0))|$($stats.Bare[1])") }
    $extensions = $stats.Ext.GetEnumerator() | Where-Object { $_.Value -is [array] } | Sort-Object @{ Expression = { $_.Value[0] }; Descending = $true }, @{ Expression = { $_.Key }; Descending = $false } | Select-Object -First 24
    foreach ($item in $extensions) { $output.Add("ext|$($item.Key)|$([long][math]::Round($item.Value[0] / 1024.0))|$($item.Value[1])") }
    return $output.ToArray()
}

# How many entries sit directly in a folder, 0 when it cannot be read. With $Prefix only the names
# that start with it are counted (the Recycle Bin keeps a $R file per deleted item next to a $I note).
function Get-SpaceEntryCount([string]$Path, [string]$Prefix = '') {
    try {
        $count = 0
        foreach ($entry in (New-Object System.IO.DirectoryInfo($Path)).GetFileSystemInfos()) {
            if ($Prefix -eq '' -or $entry.Name.StartsWith($Prefix, [System.StringComparison]::OrdinalIgnoreCase)) { $count++ }
        }
        return $count
    } catch {
        return 0
    }
}

# False when a folder cannot be listed, as with Windows.old or the Windows update downloads without
# admin rights. Only the first entry is asked for, so a huge folder costs nothing.
function Test-SpaceReadable([string]$Path) {
    try {
        [void][System.IO.Directory]::EnumerateFileSystemEntries($Path).GetEnumerator().MoveNext()
        return $true
    } catch {
        return $false
    }
}

# Size and count of the exe, msi and iso files directly inside a folder (not deeper). Files that
# only exist in the cloud are left out. A file with a reparse point is kept: OneDrive files that are
# on the device have one, and a file link has a size of its own that is too small to matter.
# Returns $null when the folder cannot be read.
function Get-SpaceInstallers([string]$Path) {
    $cloudOnly = 0x400000 -bor 0x40000 -bor 0x1000
    $bytes = [long]0
    $count = 0
    try { $files = (New-Object System.IO.DirectoryInfo($Path)).GetFiles() } catch { return $null }
    foreach ($file in $files) {
        try {
            if ([int]$file.Attributes -band $cloudOnly) { continue }
            if (@('.exe', '.msi', '.iso') -notcontains $file.Extension.ToLowerInvariant()) { continue }
            $bytes += [long]$file.Length
            $count++
        } catch {
            continue
        }
    }
    return [pscustomobject]@{ Bytes = $bytes; Count = $count }
}

# The biggest single files under $Root: at most $Limit, each of at least $MinBytes, until $StopAtMs on
# $Clock. Hidden and system items, AppData, junctions, links and files that only exist in the cloud
# are never entered or listed. Names and the folder directly above are kept, never a full path.
function Get-SpaceBiggestFiles([string]$Root, $Clock, [long]$StopAtMs, [long]$MinBytes, [int]$Limit) {
    $separators = [char[]]@('\', '/')
    $directory = [int][System.IO.FileAttributes]::Directory
    $reparse = [int][System.IO.FileAttributes]::ReparsePoint
    $hiddenOrSystem = [int][System.IO.FileAttributes]::Hidden -bor [int][System.IO.FileAttributes]::System
    $cloudOnly = 0x400000 -bor 0x40000 -bor 0x1000
    $found = New-Object System.Collections.Generic.List[object]
    $capped = $false
    $start = New-Object System.IO.DirectoryInfo($Root)
    $rootFull = $start.FullName.TrimEnd($separators)
    $stack = New-Object 'System.Collections.Generic.Stack[object]'
    if (-not ([int]$start.Attributes -band $reparse)) { $stack.Push($start) }
    while ($stack.Count -gt 0) {
        if ($Clock.ElapsedMilliseconds -ge $StopAtMs) { $capped = $true; break }
        $current = $stack.Pop()
        $atRoot = $current.FullName.TrimEnd($separators) -ieq $rootFull
        try { $entries = $current.GetFileSystemInfos() } catch { continue }
        foreach ($entry in $entries) {
            try {
                $attributes = [int]$entry.Attributes
                if ($attributes -band $hiddenOrSystem) { continue }
                if ($attributes -band $directory) {
                    if (($attributes -band $reparse) -or ($atRoot -and $entry.Name -ieq 'AppData')) { continue }
                    $stack.Push($entry)
                    continue
                }
                if ($attributes -band $cloudOnly) { continue }
                $length = [long]$entry.Length
                if ($length -lt $MinBytes) { continue }
                $folder = ''
                if (-not $atRoot) { $folder = $current.Name }
                $found.Add([pscustomobject]@{ Name = $entry.Name; Bytes = $length; Folder = $folder })
                if ($found.Count -gt $Limit * 3) {
                    $kept = @($found | Sort-Object @{ Expression = { $_.Bytes }; Descending = $true }, Name | Select-Object -First ($Limit * 2))
                    $found.Clear()
                    foreach ($item in $kept) { $found.Add($item) }
                }
            } catch {
                continue
            }
        }
    }
    $ordered = @($found | Sort-Object @{ Expression = { $_.Bytes }; Descending = $true }, Name | Select-Object -First ($Limit * 3))
    return [pscustomobject]@{ Files = $ordered; Capped = $capped }
}

# Looks once, read only, in the well-known places where space can be got back and for the biggest
# files, never longer than the cap (60 seconds by default). Returns the lines of the "Space you can
# get back" section; progress goes to the screen. Nothing is deleted or changed.
function Get-SpaceReport {
    $SpaceCapSeconds = 60
    if ($env:PCC_SPACE_CAP -match '^[1-9][0-9]{0,4}$') { $SpaceCapSeconds = [int]$env:PCC_SPACE_CAP }
    $minKb = 51200
    if ($env:PCC_SPACE_MIN_KB -match '^[1-9][0-9]{0,8}$') { $minKb = [long]$env:PCC_SPACE_MIN_KB }
    $clock = [System.Diagnostics.Stopwatch]::StartNew()
    $capMs = [long]$SpaceCapSeconds * 1000
    $capped = $false
    $profilePath = $env:PCC_SPACE_PROFILE
    if (-not $profilePath) { $profilePath = $env:USERPROFILE }
    $windowsPath = $env:PCC_SPACE_WINDIR
    if (-not $windowsPath) { $windowsPath = $env:SystemRoot }
    $driveRoot = $env:PCC_SPACE_DRIVE
    if (-not $driveRoot) { $driveRoot = $env:SystemDrive + '\' }
    $recycle = $env:PCC_SPACE_RECYCLE
    if (-not $recycle) {
        try { $recycle = $driveRoot.TrimEnd('\') + '\$Recycle.Bin\' + [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value } catch { $recycle = '' }
    }
    $temp = $env:PCC_SPACE_TEMP
    if (-not $temp) { $temp = $env:TEMP }

    Write-Host "Looking for space you can get back, stopping after $SpaceCapSeconds seconds at the latest..."
    Write-Host '  Checking the Recycle Bin, temporary files and installers...'
    $candidates = @(
        @{ Key = 'recycle'; Path = $recycle; Prefix = '$R' },
        @{ Key = 'temp'; Path = $temp; Prefix = '' },
        @{ Key = 'systemtemp'; Path = (Join-Path $windowsPath 'Temp'); Prefix = '' },
        @{ Key = 'updates'; Path = (Join-Path (Join-Path $windowsPath 'SoftwareDistribution') 'Download'); Prefix = '' },
        @{ Key = 'installers'; Path = (Join-Path $profilePath 'Downloads'); Prefix = '' },
        @{ Key = 'windowsold'; Path = (Join-Path $driveRoot 'Windows.old'); Prefix = '' }
    )
    $present = @($candidates | Where-Object { $_.Path -and (Test-Path -LiteralPath $_.Path -PathType Container) -and (Test-StorageFixedPath $_.Path) })
    $output = New-Object System.Collections.Generic.List[string]
    $lines = New-Object System.Collections.Generic.List[string]
    $placesEnd = [long]($capMs * 40 / 100)
    $left = [double]$present.Count
    foreach ($place in $present) {
        $slice = Get-StorageSlice $clock $placesEnd 1 $left
        $left -= 1
        if ($slice -le 0) { $capped = $true; continue }
        if (-not (Test-SpaceReadable $place.Path)) {
            $lines.Add("unreadable|$($place.Key)")
            continue
        }
        if ($place.Key -eq 'installers') {
            $found = Get-SpaceInstallers $place.Path
            if ($found -and $found.Count -gt 0) { $lines.Add("reclaim|installers|$([long][math]::Round($found.Bytes / 1024.0))|$($found.Count)") }
            continue
        }
        $result = Measure-StorageTree $place.Path $clock ($clock.ElapsedMilliseconds + $slice) $null $null
        if ($result.Capped) { $capped = $true; continue }
        $lines.Add("reclaim|$($place.Key)|$([long][math]::Round($result.Bytes / 1024.0))|$(Get-SpaceEntryCount $place.Path $place.Prefix)")
    }

    Write-Host '  Looking for the biggest files in your user folder...'
    $files = @()
    if ((Test-Path -LiteralPath $profilePath -PathType Container) -and (Test-StorageFixedPath $profilePath)) {
        $kinds = @{}
        $groups = [ordered]@{
            Photos    = 'jpg jpeg png gif heic heif bmp tif tiff webp raw cr2 nef arw dng'
            Videos    = 'mp4 mov avi mkv wmv m4v webm flv mpg mpeg 3gp'
            Music     = 'mp3 m4a wav flac aac ogg wma aiff aif opus'
            Documents = 'pdf doc docx xls xlsx ppt pptx txt rtf odt ods odp csv pages numbers key md epub'
            Archives  = 'zip rar 7z tar gz tgz bz2 xz iso zst cab'
            Apps      = 'exe msi dmg pkg app ipa apk appx msix'
        }
        foreach ($kind in $groups.Keys) {
            foreach ($extension in $groups[$kind].Split(' ', [System.StringSplitOptions]::RemoveEmptyEntries)) { $kinds[$extension] = $kind }
        }
        $search = Get-SpaceBiggestFiles $profilePath $clock $capMs ([long]$minKb * 1024) 10
        if ($search.Capped) { $capped = $true }
        foreach ($file in $search.Files) {
            if ($files.Count -ge 10) { break }
            $name = Get-StorageFolderLabel $file.Name 80
            if ($null -eq $name) { continue }
            $folder = ''
            if ($file.Folder -ne '') { $folder = [string](Get-StorageFolderLabel $file.Folder 60) }
            $dot = $file.Name.LastIndexOf('.')
            $extension = ''
            if ($dot -gt 0 -and $dot -lt $file.Name.Length - 1) { $extension = $file.Name.Substring($dot + 1).ToLowerInvariant() }
            $kind = 'Other'
            if ($extension -ne '' -and $kinds.ContainsKey($extension)) { $kind = $kinds[$extension] }
            $kb = [long][math]::Round($file.Bytes / 1024.0)
            if ($kb -lt 1) { continue }
            $files += "file|$name|$kb|$kind|$folder"
        }
    }

    $scan = 'complete'
    if ($capped) {
        $scan = "partial (time limit of $SpaceCapSeconds seconds reached)"
        Write-Host '  The time limit was reached, so these numbers are partial.'
    }
    $output.Add("scan: $scan")
    $output.Add("seconds: $([int][math]::Floor($clock.Elapsed.TotalSeconds))")
    $output.Add("limit: $SpaceCapSeconds")
    $output.Add('format: 1')
    foreach ($line in $lines) { $output.Add($line) }
    foreach ($line in $files) { $output.Add($line) }
    return $output.ToArray()
}

# Last run dates from Prefetch file names such as SPOTIFY.EXE-1A2B3C4D.pf: the exe name, squeezed to
# lower case letters and digits, and the newest write time of its files. $Files needs Name and
# LastWriteTime. Generic exe names (setup, update...) and very short ones are never used.
function Get-UnusedAppsRunMap($Files) {
    $generic = 'setup', 'install', 'installer', 'update', 'updater', 'uninstall', 'unins000', 'launcher', 'helper', 'service', 'agent', 'config', 'bootstrapper'
    $map = @{}
    foreach ($file in @($Files)) {
        if ($file.Name -notmatch '^(.+)\.exe-[0-9A-Fa-f]{8}\.pf$') { continue }
        $exe = ($Matches[1] -replace '[^A-Za-z0-9]', '').ToLowerInvariant()
        if ($exe.Length -lt 4 -or $generic -contains $exe) { continue }
        if (-not $map.ContainsKey($exe) -or $file.LastWriteTime -gt $map[$exe]) { $map[$exe] = $file.LastWriteTime }
    }
    return $map
}

# Picks the apps that may be unused from the Uninstall registry entries in $Records (DisplayName,
# Publisher, EstimatedSize in KB, InstallDate as yyyyMMdd, SystemComponent, ParentKeyName, ReleaseType).
# $RunMap is the result of Get-UnusedAppsRunMap, or $null when Prefetch cannot be read. An app whose
# run date is known is listed when it is 12 months old or more; without a run date it is listed as
# "unknown" when it was installed 12 months ago or earlier. Output lines are app|name|KB|last.
function Get-UnusedAppsRows($Records, $RunMap, [datetime]$Today, [long]$MinKb, [int]$Limit) {
    # Drivers, runtimes and parts of Windows are not apps a person chose, and are never suggested for removal.
    $skipPublisher = '(?i)microsoft|windows|intel|nvidia|advanced micro devices|^amd\b|realtek|qualcomm|synaptics|broadcom|mediatek'
    $skipName = '(?i)^(microsoft|windows|update for|security update|hotfix)\b|\bKB\d{6,}\b|\.net\b|visual c\+\+|webview2|redistributable|\bruntime\b|\bdriver\b|\bfirmware\b'
    $cutoff = $Today.Date.AddYears(-1)
    $best = @{}
    foreach ($record in @($Records)) {
        $name = Get-StorageFolderLabel ([string]$record.DisplayName) 60
        if ($null -eq $name) { continue }
        if ($record.SystemComponent -eq 1 -or $record.ParentKeyName -or ([string]$record.ReleaseType) -match '(?i)update|hotfix|patch') { continue }
        if ($name -match $skipName -or ([string]$record.Publisher) -match $skipPublisher) { continue }
        $kb = [long]0
        if (-not [long]::TryParse([string]$record.EstimatedSize, [ref]$kb) -or $kb -lt $MinKb -or $kb -gt 2147483647) { continue }
        $last = $null
        if ($null -ne $RunMap) {
            $squeezed = ($name -replace '[^A-Za-z0-9]', '').ToLowerInvariant()
            foreach ($exe in $RunMap.Keys) {
                if ($squeezed.Contains($exe) -and ($null -eq $last -or $RunMap[$exe] -gt $last)) { $last = $RunMap[$exe] }
            }
        }
        $label = 'unknown'
        if ($null -ne $last) {
            if ($last.Date -gt $cutoff) { continue }
            $label = $last.ToString('yyyy-MM-dd')
        } else {
            $installed = [datetime]::MinValue
            $text = ([string]$record.InstallDate).Trim()
            if ($text -notmatch '^\d{8}$' -or -not [datetime]::TryParseExact($text, 'yyyyMMdd', [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::None, [ref]$installed)) { continue }
            if ($installed.Year -lt 2000 -or $installed.Date -gt $cutoff) { continue }
        }
        $key = $name.ToLowerInvariant()
        if (-not $best.ContainsKey($key) -or $best[$key].Kb -lt $kb) { $best[$key] = [pscustomobject]@{ Name = $name; Kb = $kb; Last = $label } }
    }
    $ordered = @($best.Values | Sort-Object @{ Expression = { $_.Kb }; Descending = $true }, Name)
    $rows = New-Object System.Collections.Generic.List[string]
    foreach ($app in ($ordered | Select-Object -First $Limit)) { $rows.Add("app|$($app.Name)|$($app.Kb)|$($app.Last)") }
    if ($ordered.Count -gt $Limit) { $rows.Add("more|$($ordered.Count - $Limit)") }
    return $rows.ToArray()
}

# Looks once, read only, at the programs in the Uninstall registry keys (name, size, install date; no
# publisher and no install path is kept) that were not used for 12 months or more, never longer than
# the cap (45 seconds by default). Run dates come from Prefetch only when that folder can be read
# without admin rights; otherwise an old install date is listed with "unknown" as the last use.
# Returns the lines of the "Apps you may not need" section; progress goes to the screen.
function Get-UnusedAppsReport {
    $cap = 45
    if ($env:PCC_APPS_CAP -match '^[1-9][0-9]{0,4}$') { $cap = [int]$env:PCC_APPS_CAP }
    $minKb = 102400
    if ($env:PCC_APPS_MIN_KB -match '^[1-9][0-9]{0,8}$') { $minKb = [long]$env:PCC_APPS_MIN_KB }
    $clock = [System.Diagnostics.Stopwatch]::StartNew()
    $today = Get-Date
    Write-Host "Looking for apps you may not need, stopping after $cap seconds at the latest..."
    $paths = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*'
    if ($env:PCC_APPS_REGISTRY) { $paths = @($env:PCC_APPS_REGISTRY) }
    $records = @(Get-ItemProperty -Path $paths -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName } | Select-Object DisplayName, Publisher, EstimatedSize, InstallDate, SystemComponent, ParentKeyName, ReleaseType)
    $runMap = $null
    try {
        $prefetch = $env:PCC_APPS_PREFETCH
        if (-not $prefetch) { $prefetch = Join-Path $env:SystemRoot 'Prefetch' }
        if ($clock.ElapsedMilliseconds -ge [long]$cap * 1000) { throw 'time' }
        $files = @(Get-ChildItem -LiteralPath $prefetch -Filter '*.pf' -ErrorAction Stop | Select-Object Name, LastWriteTime)
        if ($files.Count -gt 0) { $runMap = Get-UnusedAppsRunMap $files }
    } catch {
        $runMap = $null
    }
    $scan = 'complete'
    if ($clock.ElapsedMilliseconds -ge [long]$cap * 1000) {
        $scan = "partial (time limit of $cap seconds reached)"
        Write-Host '  The time limit was reached, so this list may be incomplete.'
    }
    $rows = Get-UnusedAppsRows $records $runMap $today $minKb 15
    $output = New-Object System.Collections.Generic.List[string]
    $output.Add("scan: $scan")
    $output.Add("seconds: $([int][math]::Floor($clock.Elapsed.TotalSeconds))")
    $output.Add("limit: $cap")
    $output.Add('format: 1')
    $output.Add("scanned: $($today.ToString('yyyy-MM-dd'))")
    foreach ($row in $rows) { $output.Add($row) }
    return $output.ToArray()
}

# The creation date (yyyy-MM-dd) of the newest restore point, "none" when there is none, or "could
# not check". Get-ComputerRestorePoint runs in a job so a hung WMI call cannot stop the scan, and
# for at most $TimeoutSeconds. Without admin rights an empty answer is not trusted as "none".
function Get-LastRestorePoint([int]$TimeoutSeconds = 15) {
    $job = $null
    try {
        $job = Start-Job -ScriptBlock {
            Get-ComputerRestorePoint -ErrorAction Stop | Sort-Object SequenceNumber -Descending | Select-Object -First 1 | ForEach-Object { [System.Management.ManagementDateTimeConverter]::ToDateTime($_.CreationTime).ToString('yyyy-MM-dd') }
        }
        if (-not (Wait-Job -Job $job -Timeout $TimeoutSeconds)) { return 'could not check' }
        $date = @(Receive-Job -Job $job -ErrorAction Stop) | Select-Object -First 1
        if ($date -match '^\d{4}-\d{2}-\d{2}$') { return [string]$date }
        $admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
        if ($admin) { return 'none' }
        return 'could not check'
    } catch {
        return 'could not check'
    } finally {
        if ($null -ne $job) { Remove-Job -Job $job -Force -ErrorAction SilentlyContinue }
    }
}

# The lines of the "Backup freshness" section from facts the backup step already read. $FileHistoryNote
# is set when File History could not be read, $LastBackup is a [datetime] or $null, $OneDriveFolders
# lists Desktop, Documents and Pictures when they live in OneDrive, and $RestorePoint comes from
# Get-LastRestorePoint.
function Get-BackupFreshnessLines([datetime]$Today, $FileHistoryNote, [bool]$FileHistoryOn, $LastBackup, $OneDriveFolders, [string]$RestorePoint) {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    $lines.Add("scanned: $($Today.ToString('yyyy-MM-dd'))")
    if ($FileHistoryNote) {
        $lines.Add('filehistory: could not check')
        $lines.Add('filehistory-last: could not check')
    } elseif (-not $FileHistoryOn) {
        $lines.Add('filehistory: not set up')
        $lines.Add('filehistory-last: none')
    } else {
        $lines.Add('filehistory: set up')
        if ($null -ne $LastBackup) { $lines.Add("filehistory-last: $(([datetime]$LastBackup).ToString('yyyy-MM-dd'))") } else { $lines.Add('filehistory-last: could not check') }
    }
    $folders = @($OneDriveFolders | Where-Object { $_ })
    if ($folders.Count -gt 0) { $lines.Add("onedrive-folders: $($folders -join ', ')") } else { $lines.Add('onedrive-folders: none') }
    $lines.Add("restorepoint-last: $RestorePoint")
    return $lines.ToArray()
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

# RustDesk is never started or asked: only its id line is read, and no other line is kept or printed.
# The service copy needs admin rights, so it is only tried with them.
function Get-RustDeskId {
    $paths = @("$env:APPDATA\RustDesk\config\RustDesk.toml")
    if ($IsAdmin) { $paths += 'C:\Windows\ServiceProfiles\LocalService\AppData\Roaming\RustDesk\config\RustDesk.toml' }
    foreach ($path in $paths) {
        try {
            if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
            foreach ($line in @(Get-Content -LiteralPath $path -ErrorAction Stop)) {
                if ($line -match '^\[') { break }
                if ($line -cmatch "^id\s*=\s*['""]([A-Za-z0-9_-]{4,64})['""]\s*$") { return $Matches[1] }
            }
        } catch {
            continue
        }
    }
    return $null
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

Write-Host 'NL: Deze scan leest alleen hardware, schijfgebruik, map- en appnamen en beveiligingsinstellingen, wijzigt niets en verstuurt het rapport alleen als u een scancode invoert.'; Write-Host "EN: This scan only reads hardware, disk use, folder and app names and security settings, changes nothing, and uploads the report only if you enter a scan code. $DefaultUrl/privacy"
$found = $null
$foundCode = $null
try { $found = Get-UploadToken $Token $PSScriptRoot } catch { Write-Host "Upload skipped: could not read the token ($(One-Line $_.Exception.Message))." }
if (-not $found) {
    try { $foundCode = Get-ScanCode $Code } catch { Write-Host "Upload skipped: could not read the scan code ($(One-Line $_.Exception.Message))." }
}

function Get-ScriptBanner { "PC Checkup Windows script, version $ScriptVersion" }

function Get-ScriptVersionLine { "- Script version: $ScriptVersion" }

Write-Host (Get-ScriptBanner)
Write-Host 'Running checkup, this can take a few minutes...'

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

# ---------- Windows health and security (read only; every value that cannot be read says could not check) ----------
$HealthBudgetSeconds = 90
$HealthCallSeconds = 20
$HealthMaxDisks = 8
$HealthMaxNames = 5
$HealthNeverScanned = 4294967295

# Runs a read in its own runspace so a stuck query cannot hang the scan: it gets $Seconds, or what is
# left of the whole step's budget. The block must not use the helpers of this script. A runspace
# that did not answer is told to stop but not disposed, because disposing it can block.
function Try-Capped([scriptblock]$Block, [int]$Seconds = $HealthCallSeconds) {
    $left = [int]($HealthBudgetSeconds - $script:HealthClock.Elapsed.TotalSeconds)
    if ($left -le 0) {
        $script:HealthCut = $true
        return [pscustomobject]@{ Value = $null; Note = 'unavailable (the time limit of this step was reached)' }
    }
    $limit = [math]::Min($Seconds, $left)
    $shell = [powershell]::Create()
    $stuck = $false
    try {
        $null = $shell.AddScript("`$ErrorActionPreference = 'Stop'`n" + $Block.ToString())
        $handle = $shell.BeginInvoke()
        if (-not $handle.AsyncWaitHandle.WaitOne($limit * 1000)) {
            $stuck = $true
            $script:HealthCut = $true
            $null = $shell.BeginStop($null, $null)
            return [pscustomobject]@{ Value = $null; Note = "unavailable (took longer than $limit seconds)" }
        }
        $items = @($shell.EndInvoke($handle))
        return [pscustomobject]@{ Value = $items; Note = $null }
    } catch {
        return [pscustomobject]@{ Value = $null; Note = (Explain-Error $_) }
    } finally {
        if (-not $stuck) { $shell.Dispose() }
    }
}

function Get-HealthText($Value, [int]$Limit = 60) {
    $text = One-Line (([string]$Value) -replace '[\x00-\x1f\x7f|]', ' ')
    if ($text.Length -gt $Limit) { $text = $text.Substring(0, $Limit).Trim() }
    return $text
}

function Get-HealthCount($Value, [double]$Max = 1000000000) {
    if ($null -eq $Value -or "$Value" -eq '') { return $CouldNot }
    $number = 0.0
    if (-not [double]::TryParse("$Value", [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref]$number)) { return $CouldNot }
    if ([double]::IsNaN($number) -or [double]::IsInfinity($number) -or $number -lt 0 -or $number -gt $Max) { return $CouldNot }
    return ([math]::Round($number)).ToString('0', [Globalization.CultureInfo]::InvariantCulture)
}

function Get-HealthAge($Value) {
    if ("$Value" -eq "$HealthNeverScanned") { return 'never' }
    return Get-HealthCount $Value 36500
}

function Get-HealthSwitch($Value) {
    if ($Value -is [bool]) { return $(if ($Value) { 'on' } else { 'off' }) }
    return $CouldNot
}

function Get-HealthRegistry([string]$Path, [string]$Name) {
    $read = Try-Run { (Get-ItemProperty -LiteralPath $Path -Name $Name -ErrorAction Stop).$Name }
    return $read.Value
}

function Get-HealthDriveLines {
    $read = Try-Capped {
        foreach ($disk in @(Get-PhysicalDisk)) {
            $counter = $null
            try { $counter = $disk | Get-StorageReliabilityCounter } catch { $counter = $null }
            [pscustomobject]@{
                Name = $disk.FriendlyName; Media = "$($disk.MediaType)"; Bus = "$($disk.BusType)"; Size = $disk.Size
                Health = "$($disk.HealthStatus)"; Status = (@($disk.OperationalStatus) -join ',')
                Wear = $counter.Wear; Temperature = $counter.Temperature; ReadErrors = $counter.ReadErrorsTotal
                WriteErrors = $counter.WriteErrorsTotal; Hours = $counter.PowerOnHours
            }
        }
    } 30
    $disks = @($read.Value | Where-Object { $_ } | Select-Object -First $HealthMaxDisks)
    if ($read.Note -or $disks.Count -eq 0) { return "Drives: $CouldNot" }
    "Drives: $($disks.Count)"
    $number = 0
    foreach ($disk in $disks) {
        $number++
        $name = Get-HealthText $disk.Name 50
        if (-not $name) { $name = "Drive $number" }
        $media = Get-HealthText $disk.Media 20
        if (-not $media -or $media -eq 'Unspecified') { $media = $CouldNot }
        $bus = Get-HealthText $disk.Bus 20
        if (-not $bus) { $bus = $CouldNot }
        $size = $CouldNot
        if ($disk.Size -gt 0) { $size = "$(Get-HealthCount ([math]::Round($disk.Size / 1GB)) 1000000) GB" }
        $health = Get-HealthText $disk.Health 20
        if (-not $health) { $health = $CouldNot }
        $status = Get-HealthText $disk.Status 40
        if (-not $status) { $status = $CouldNot }
        $wear = Get-HealthCount $disk.Wear 100
        $temperature = Get-HealthCount $disk.Temperature 150
        $readErrors = Get-HealthCount $disk.ReadErrors
        $writeErrors = Get-HealthCount $disk.WriteErrors
        $hours = Get-HealthCount $disk.Hours 1000000
        "Drive ${number}: $name | type $media | bus $bus | size $size | health $health | status $status | wear $wear | temperature $temperature | read errors $readErrors | write errors $writeErrors | power-on hours $hours"
    }
}

function Get-HealthDefenderLines {
    $read = Try-Capped { Get-MpComputerStatus | Select-Object RealTimeProtectionEnabled, AntivirusSignatureAge, QuickScanAge, FullScanAge, IsTamperProtected }
    $mp = @($read.Value | Where-Object { $_ }) | Select-Object -First 1
    "Defender real-time protection: $(Get-HealthSwitch $mp.RealTimeProtectionEnabled)"
    "Defender signature age days: $(Get-HealthCount $mp.AntivirusSignatureAge 36500)"
    "Defender last quick scan age days: $(Get-HealthAge $mp.QuickScanAge)"
    "Defender last full scan age days: $(Get-HealthAge $mp.FullScanAge)"
    "Defender tamper protection: $(Get-HealthSwitch $mp.IsTamperProtected)"
    $av = Try-Capped { Get-CimInstance -Namespace root\SecurityCenter2 -ClassName AntiVirusProduct -OperationTimeoutSec 15 | Select-Object -ExpandProperty displayName }
    if ($av.Note) { 'Other antivirus: ' + $CouldNot; return }
    $names = @($av.Value | ForEach-Object { Get-HealthText $_ 40 } | Where-Object { $_ -and $_ -notmatch 'Windows Defender|Microsoft Defender' } | Select-Object -Unique -First $HealthMaxNames)
    'Other antivirus: ' + $(if ($names.Count -gt 0) { $names -join ', ' } else { 'none' })
}

function Get-HealthBitLockerLine {
    $read = Try-Capped {
        $state = ''
        try { $state = "$((Get-BitLockerVolume -MountPoint $env:SystemDrive).ProtectionStatus)" } catch { $state = '' }
        if ($state -eq 'On' -or $state -eq '1') { return 'on' }
        if ($state -eq 'Off' -or $state -eq '0') { return 'off' }
        $code = (New-Object -ComObject Shell.Application).NameSpace("$env:SystemDrive\").Self.ExtendedProperty('System.Volume.BitLockerProtection')
        if ($code -eq 1) { return 'on' }
        if ($code -eq 0) { return 'off' }
    }
    $state = @($read.Value | Where-Object { $_ }) | Select-Object -First 1
    "BitLocker system drive: $(if ($state) { $state } else { $CouldNot })"
}

function Get-HealthFirewallLines {
    $read = Try-Capped { Get-NetFirewallProfile | Select-Object Name, Enabled }
    foreach ($profile in 'Domain', 'Private', 'Public') {
        $found = @($read.Value | Where-Object { $_ -and "$($_.Name)" -eq $profile }) | Select-Object -First 1
        $state = $CouldNot
        if ($found -and "$($found.Enabled)" -eq 'True') { $state = 'on' }
        if ($found -and "$($found.Enabled)" -eq 'False') { $state = 'off' }
        "Firewall ${profile}: $state"
    }
}

function Get-HealthRemoteLines {
    $deny = Get-HealthRegistry 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server' 'fDenyTSConnections'
    $rdp = $CouldNot
    if ("$deny" -eq '0') { $rdp = 'on' }
    if ("$deny" -eq '1') { $rdp = 'off' }
    $auth = Get-HealthRegistry 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp' 'UserAuthentication'
    $nla = $CouldNot
    if ("$auth" -eq '1') { $nla = 'required' }
    if ("$auth" -eq '0') { $nla = 'not required' }
    "Remote Desktop: $rdp"
    "Remote Desktop network level authentication: $nla"
    $smb = Try-Capped { [int][bool](Get-SmbServerConfiguration).EnableSMB1Protocol }
    $value = @($smb.Value | Where-Object { $null -ne $_ }) | Select-Object -First 1
    if ($null -eq $value) { $value = Get-HealthRegistry 'HKLM:\SYSTEM\CurrentControlSet\Services\LanmanServer\Parameters' 'SMB1' }
    $smb1 = $CouldNot
    if ("$value" -eq '1') { $smb1 = 'on' }
    if ("$value" -eq '0') { $smb1 = 'off' }
    "SMBv1: $smb1"
}

function Get-HealthAccountLines {
    $group = Try-Capped {
        $disabled = @(Get-LocalUser | Where-Object { $_.Enabled -eq $false } | ForEach-Object { $_.SID.Value })
        @(Get-LocalGroupMember -SID 'S-1-5-32-544' | Where-Object { $disabled -notcontains $_.SID.Value }).Count
    }
    $count = @($group.Value | Where-Object { $null -ne $_ }) | Select-Object -First 1
    "Local administrator accounts: $(Get-HealthCount $count 100000)"
    $builtin = Try-Capped { (Get-LocalUser | Where-Object { $_.SID.Value -like 'S-1-5-21-*-500' } | Select-Object -First 1).Enabled }
    $enabled = @($builtin.Value | Where-Object { $_ -is [bool] }) | Select-Object -First 1
    "Built-in Administrator account: $(if ($enabled -eq $true) { 'enabled' } elseif ($enabled -eq $false) { 'disabled' } else { $CouldNot })"
}

function Get-HealthPolicyLines {
    $pause = Try-Run {
        $key = 'HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings'
        if (-not (Test-Path -LiteralPath $key)) { return 'no' }
        $settings = Get-ItemProperty -LiteralPath $key -ErrorAction Stop
        $latest = $null
        foreach ($text in @($settings.PauseUpdatesExpiryTime, $settings.PauseFeatureUpdatesEndTime, $settings.PauseQualityUpdatesEndTime)) {
            if (-not $text) { continue }
            $when = [datetime]::MinValue
            if (-not [datetime]::TryParse("$text", [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::AdjustToUniversal -bor [Globalization.DateTimeStyles]::AssumeUniversal, [ref]$when)) { throw 'unreadable pause date' }
            if (-not $latest -or $when -gt $latest) { $latest = $when }
        }
        if ($latest -and $latest -gt (Get-Date).ToUniversalTime()) { return "yes, until $($latest.ToString('yyyy-MM-dd'))" }
        return 'no'
    }
    "Windows Update paused: $(if ($pause.Value) { $pause.Value } else { $CouldNot })"
    $policies = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'
    $lua = Get-HealthRegistry $policies 'EnableLUA'
    $uac = $CouldNot
    if ("$lua" -eq '1') { $uac = 'on' }
    if ("$lua" -eq '0') { $uac = 'off' }
    $consent = Get-HealthRegistry $policies 'ConsentPromptBehaviorAdmin'
    $prompt = $CouldNot
    if ("$consent" -eq '0') { $prompt = 'never asks' }
    if ("$consent" -match '^[1-5]$') { $prompt = 'asks' }
    "UAC: $uac"
    "UAC administrator prompt: $prompt"
}

function Get-WindowsHealthSecurityLines {
    $script:HealthClock = [Diagnostics.Stopwatch]::StartNew()
    $script:HealthCut = $false
    Get-HealthDriveLines
    Get-HealthDefenderLines
    Get-HealthBitLockerLine
    Get-HealthFirewallLines
    Get-HealthRemoteLines
    Get-HealthAccountLines
    Get-HealthPolicyLines
    if ($script:HealthCut) { 'Time limit reached: some values were not read' }
}

# ---------- Windows 11 readiness (read only; CIM queries are capped, the rest are instant local calls) ----------
$CouldNot = 'could not check'
$ReadinessCimSeconds = 20
$TpmDeviceIds = '^ACPI\\(MSFT0101|MSFT0200|IFX|NTC|STM|INTC|AMDI|BCM)'
$SocketedFormFactors = 8, 12

function Get-FirmwareMode {
    $known = Try-Run {
        Add-Type -Namespace Pcc -Name Firmware -MemberDefinition '[DllImport("kernel32.dll")] public static extern bool GetFirmwareType(ref uint firmwareType);'
        $type = [uint32]0
        if (-not [Pcc.Firmware]::GetFirmwareType([ref]$type)) { throw 'GetFirmwareType failed' }
        $type
    }
    if ($known.Value -eq 2) { return 'UEFI' }
    if ($known.Value -eq 1) { return 'BIOS (legacy)' }
    if (Test-Path -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Control\SecureBoot\State') { return 'UEFI' }
    return $CouldNot
}

function Get-SecureBootReadiness([string]$Firmware) {
    $confirmed = Try-Run { Confirm-SecureBootUEFI -ErrorAction Stop }
    if ($null -ne $confirmed.Value) { return $(if ($confirmed.Value) { 'on' } else { 'off' }) }
    $stored = Try-Run { Get-RegValue 'HKLM:\SYSTEM\CurrentControlSet\Control\SecureBoot\State' 'UEFISecureBootEnabled' }
    if ($null -ne $stored.Value) { return $(if ([int]$stored.Value -eq 1) { 'on' } else { 'off' }) }
    if ($Firmware -eq 'BIOS (legacy)') { return 'not supported (legacy BIOS)' }
    return $CouldNot
}

function Get-TpmReadiness {
    $facts = [ordered]@{ Presence = $CouldNot; Version = $CouldNot; Ready = $CouldNot; Device = $null }
    $wmi = Try-Run { @(Get-CimInstance -Namespace 'root\cimv2\Security\MicrosoftTpm' -ClassName Win32_Tpm -OperationTimeoutSec $ReadinessCimSeconds -ErrorAction Stop) }
    $chip = @($wmi.Value | Where-Object { $_ }) | Select-Object -First 1
    if ($chip) {
        $facts.Presence = 'present'
        if ((One-Line $chip.SpecVersion) -match '^(\d+\.\d+)') { $facts.Version = $Matches[1] }
        if ($null -ne $chip.IsEnabled_InitialValue -and $null -ne $chip.IsActivated_InitialValue) {
            $facts.Ready = $(if ($chip.IsEnabled_InitialValue -and $chip.IsActivated_InitialValue) { 'yes' } else { 'no' })
        }
        return $facts
    }
    if (-not $wmi.Note) {
        $facts.Presence = 'not found'
        return $facts
    }
    $device = (Try-Run { Get-CimInstance Win32_PnPEntity -Filter "PNPClass='SecurityDevices'" -OperationTimeoutSec $ReadinessCimSeconds -ErrorAction Stop | Where-Object { $_.DeviceID -match $TpmDeviceIds } | Select-Object -First 1 }).Value
    if ($device) {
        $facts.Presence = 'present'
        $facts.Device = One-Line $device.Name
        if ($facts.Device -match '(\d\.\d)\s*$') { $facts.Version = $Matches[1] }
    }
    return $facts
}

function Get-MemorySlotFacts {
    $facts = [ordered]@{ Slots = $CouldNot; Form = $CouldNot; Maximum = $CouldNot; Installed = $null }
    $arrays = @((Try-Run { Get-CimInstance Win32_PhysicalMemoryArray -OperationTimeoutSec $ReadinessCimSeconds -ErrorAction Stop }).Value | Where-Object { $_ -and $_.Use -eq 3 })
    $modules = Try-Run { @(Get-CimInstance Win32_PhysicalMemory -OperationTimeoutSec $ReadinessCimSeconds -ErrorAction Stop) }
    if ($arrays.Count -gt 0 -and -not $modules.Note) {
        $total = ($arrays | Measure-Object -Property MemoryDevices -Sum).Sum
        $used = @($modules.Value | Where-Object { $_ -and $_.Capacity -gt 0 })
        $facts.Installed = [math]::Round(($used | Measure-Object -Property Capacity -Sum).Sum / 1GB)
        $facts.Slots = "$total total, $($used.Count) used"
        $socketed = @($used | Where-Object { $SocketedFormFactors -contains [int]$_.FormFactor })
        $facts.Form = $(if ($used.Count -gt 0 -and $socketed.Count -eq $used.Count) { 'socketed' } else { 'soldered or unknown' })
    }
    $kilobytes = 0
    foreach ($array in $arrays) {
        $each = $(if ($array.MaxCapacity -eq 2147483648) { [double]$array.MaxCapacityEx } else { [double]$array.MaxCapacity })
        $kilobytes += $each
    }
    if ($kilobytes -gt 0) { $facts.Maximum = "$([math]::Round($kilobytes / 1MB)) GB" }
    return $facts
}

function Get-Windows11ReadinessLines {
    $firmware = Get-FirmwareMode
    $tpmFacts = Get-TpmReadiness
    $slotFacts = Get-MemorySlotFacts
    $cpu = $cpus | Select-Object -First 1
    $systemDisk = Try-Run { [math]::Round((Get-Partition -DriveLetter $systemDrive.TrimEnd(':') -ErrorAction Stop | Get-Disk -ErrorAction Stop).Size / 1e9) }
    $diskText = $(if ($null -ne $systemDisk.Value -and $systemDisk.Value -gt 0) { "$($systemDisk.Value) GB" } else { $CouldNot })
    $installedGb = $(if ($slotFacts.Installed -gt 0) { $slotFacts.Installed } else { $ramGb })
    $ramText = $(if ($installedGb) { "$installedGb GB" } else { $CouldNot })
    $cpuText = $(if ($cpu -and (One-Line $cpu.Name)) { One-Line $cpu.Name } else { $CouldNot })
    $makerText = $(if ($cpu -and (One-Line $cpu.Manufacturer)) { One-Line $cpu.Manufacturer } else { $CouldNot })
    $familyText = $(if ($cpu -and (One-Line $cpu.Description)) { One-Line $cpu.Description } else { $CouldNot })
    "Firmware: $firmware"
    "Secure Boot: $(Get-SecureBootReadiness $firmware)"
    "TPM: $($tpmFacts.Presence)"
    "TPM version: $($tpmFacts.Version)"
    "TPM ready: $($tpmFacts.Ready)"
    if ($tpmFacts.Device) { "TPM device: $($tpmFacts.Device)" }
    "CPU: $cpuText"
    "CPU maker: $makerText"
    "CPU family: $familyText"
    "Memory: $ramText"
    "System disk: $diskText"
    "Memory slots: $($slotFacts.Slots)"
    "Memory form: $($slotFacts.Form)"
    "Memory maximum: $($slotFacts.Maximum)"
    "Run as administrator: $(if ($IsAdmin) { 'yes' } else { 'no, so the TPM may not be readable' })"
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

# Last day of security updates per Microsoft's Windows 11 release information, checked 2026-11-04. Add a row when a new version ships.
# HomePro covers Home, Pro and Pro Education; Enterprise covers Enterprise, Education and IoT Enterprise.
$Windows11Support = @(
    [pscustomobject]@{ Build = 22000; Version = '21H2'; HomePro = '2023-10-10'; Enterprise = '2024-10-08' }
    [pscustomobject]@{ Build = 22621; Version = '22H2'; HomePro = '2024-10-08'; Enterprise = '2025-10-14' }
    [pscustomobject]@{ Build = 22631; Version = '23H2'; HomePro = '2025-11-11'; Enterprise = '2026-11-10' }
    [pscustomobject]@{ Build = 26100; Version = '24H2'; HomePro = '2026-10-13'; Enterprise = '2027-10-12' }
    [pscustomobject]@{ Build = 26200; Version = '25H2'; HomePro = '2027-10-12'; Enterprise = '2028-10-10' }
    [pscustomobject]@{ Build = 28000; Version = '26H1'; HomePro = '2028-03-14'; Enterprise = '2029-03-13' }
    [pscustomobject]@{ Build = 26300; Version = '26H2'; HomePro = '2028-10-10'; Enterprise = '2029-10-09' }
)

function Get-Windows11SupportFlag([string]$Build, [string]$Caption, [string]$EditionId, [datetime]$Today) {
    if ($Build -notmatch '^\s*(\d{1,9})(\.\d+)?\s*$') { return $null }
    $number = [int]$Matches[1]
    $row = $Windows11Support | Where-Object { $_.Build -eq $number } | Select-Object -First 1
    if (-not $row) { return $null }
    if ($Caption -match 'Windows Server|LTSC' -or $EditionId -match '^(Server(?!Rdsh)|EnterpriseSN?$|IoTEnterpriseSN?$)') { return $null }
    $longTerm = $EditionId -match '^(Enterprise|Education|IoTEnterprise|ServerRdsh)N?$'
    $endDate = if ($longTerm) { $row.Enterprise } else { $row.HomePro }
    $end = [datetime]::ParseExact($endDate, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
    if ($Today.Date -le $end) { return $null }
    return "Windows 11 $($row.Version) reached end of support on $($end.ToString('d MMMM yyyy', [Globalization.CultureInfo]::InvariantCulture)), so it no longer gets security updates. Update to the newest version of Windows 11."
}

$editionId = (Try-Run { Get-RegValue $currentVersion 'EditionID' }).Value
$windows11Flag = (Try-Run { Get-Windows11SupportFlag -Build "$($os.BuildNumber)" -Caption $osCaption -EditionId $editionId -Today (Get-Date) }).Value
if ($windows11Flag) { Add-Flag $windows11Flag }

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
$storageBreakdown = Try-Run { Get-StorageBreakdown }
Add-Section 'Storage breakdown' {
    if ($storageBreakdown.Value) { $storageBreakdown.Value } else { $storageBreakdown.Note }
}
$spaceReport = Try-Run { Get-SpaceReport }
Add-Section 'Space you can get back' {
    if ($spaceReport.Value) { $spaceReport.Value } else { $spaceReport.Note }
}
$unusedApps = Try-Run { Get-UnusedAppsReport }
Add-Section 'Apps you may not need' {
    if ($unusedApps.Value) { $unusedApps.Value } else { $unusedApps.Note }
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
Add-Section 'Windows 11 readiness' { Get-Windows11ReadinessLines }
Add-Section 'Windows health and security' { Get-WindowsHealthSecurityLines }
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
$restorePoint = Get-LastRestorePoint
Add-Section 'Backup freshness' {
    Get-BackupFreshnessLines (Get-Date) $fileHistory.Note $fileHistoryOn $lastBackup.Value $oneDriveSaved $restorePoint
}
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
$rustDeskId = Get-RustDeskId
$rustDeskText = if ($rustDeskId) { $rustDeskId } else { 'not found (RustDesk is not installed, or its settings file holds no readable ID)' }

$lines = New-Object System.Collections.Generic.List[string]
$lines.Add("# Windows checkup: $HostName")
$lines.Add('')
$lines.Add('This report comes from one scan that ran once, only read information, and installed and changed nothing on this PC.')
$lines.Add('')
$lines.Add('- Format: 1')
$lines.Add((Get-ScriptVersionLine))
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
$lines.Add("- RustDesk ID: $rustDeskText")
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
