# Read-only Windows checkup. Writes a Markdown report to the Desktop.
# The scan runs once, only reads information, and installs and changes nothing: it changes no
# setting, starts no service, schedules nothing and leaves nothing running afterwards.
# Usage: powershell -ExecutionPolicy Bypass -File windows-checkup.ps1 [-Token <token>] [-Code <code>] [-Url <url>]
# Works on Windows PowerShell 5.1 and PowerShell 7. Needs no admin rights; sections that
# do need them say "unavailable (needs admin)". Keep this file ASCII only: 5.1 reads a
# file without a byte order mark as ANSI.
#
# The storage step measures folder sizes and groups the user folder by kind of file, for at most
# 150 seconds, your user folder first and then the rest of the drive. To be quick it reads several
# folders at a time with a small helper that PowerShell compiles in memory. It stores sizes and
# counts, plus the names and sizes of the biggest folders in your user profile (8 folders, and 5
# inside each of the 2 biggest), never a file name or a full path. It follows no junctions or links
# and leaves other disks alone. If the time runs out the report says the numbers are partial.
#
# The space step looks, for at most 60 seconds, at a few fixed places where space can be got back:
# the Recycle Bin, the Temp folders, downloaded Windows updates, installers in Downloads and
# Windows.old if it exists. It also notes the 10 biggest files of 50 MB or more in your user
# profile (file name, size and the name of the folder above it, never a full path), leaving out
# AppData and Steam game libraries, because deleting part of a program or game breaks it. It
# deletes nothing.
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
# The remote access and network step looks for a fixed list of known remote-access tools (TeamViewer,
# AnyDesk and the like) among installed programs and running processes and records only the names of
# those it finds, never any other app. From the hosts file it records how many extra host names it holds
# and which of a fixed list of well-known sites (Google, Microsoft, Apple, Dutch banks) it sends
# elsewhere, never its lines. It records whether a proxy is set and only its host, the addresses of the
# DNS servers in use, and the names of the antivirus programs Windows Security Center knows and whether
# each is active. Every read has a time limit.
#
# The activation and BitLocker step records whether Windows is activated (the licence status only,
# never the product key) and, for each fixed drive, whether BitLocker is on, off or suspended, read
# from the drive property Explorer shows, for at most 20 seconds. It never reads a recovery key.
#
# The memory modules step reads, for at most 25 seconds, the slot name, size, type, speed and form factor
# of each installed memory module, so a matching part can be bought. It never reads a serial or part
# number. The drives for the parts card come from the health step above.
#
# Optional upload: with a token the finished report is also sent to PC Checkup. The token is
# read from, in this order: -Token, the PC_CHECKUP_TOKEN environment variable, or a file named
# pc-checkup.token next to this script. The site is $DefaultUrl below; -Url or PC_CHECKUP_URL
# override it (https only, plus http://localhost for testing). The token is never written to
# the report or printed.
#
# Without a token the script takes the 6 digit code of "Start a scan" in the app: -Code, the
# PC_CHECKUP_CODE environment variable, or a prompt when run in a console. A token always wins
# over a code.
#
# Pressing Enter at that prompt approves the upload with a QR code instead. Once the report is saved
# the script asks the site for a pairing (that request carries nothing about this PC) and shows a QR
# code, a link and a 4 character check code. The owner opens it on a phone, checks the code and
# approves; only then is the report sent, with a token that works once. The script asks every 3
# seconds for at most 10 minutes; Ctrl-C stops it and nothing is sent. Without a console it never
# pairs.

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

# Compiles the folder walker once, in memory. It reads several folders at a time, which is what makes
# the step fast on a full drive. When compiling is not possible (or PCC_STORAGE_ENGINE is "script"),
# the walker written in PowerShell does the same work more slowly.
function Test-StorageEngine {
    if ($env:PCC_STORAGE_ENGINE -eq 'script') { return $false }
    if ($null -ne $script:PccStorageEngine) { return $script:PccStorageEngine }
    $script:PccStorageEngine = $false
    try {
        if (-not ('Pcc.StorageWalk' -as [type])) {
            Add-Type -ErrorAction Stop -TypeDefinition @'
    using System;
    using System.Collections;
    using System.Collections.Generic;
    using System.Diagnostics;
    using System.IO;
    using System.Threading;

    namespace Pcc
    {
        public sealed class StorageWalk
        {
            private sealed class Frame
            {
                public DirectoryInfo Folder;
                public string One;
                public string Two;
            }

            private sealed class Counts
            {
                public long Bytes;
                public readonly long[] Bare = new long[2];
                public readonly Dictionary<string, long[]> Kinds = new Dictionary<string, long[]>(StringComparer.Ordinal);
                public readonly Dictionary<string, long[]> Extensions = new Dictionary<string, long[]>(StringComparer.Ordinal);
                public readonly Dictionary<string, long> One = new Dictionary<string, long>(StringComparer.OrdinalIgnoreCase);
                public readonly Dictionary<string, long> Two = new Dictionary<string, long>(StringComparer.OrdinalIgnoreCase);
            }

            private const FileAttributes CloudOnly = (FileAttributes)(0x400000 | 0x40000 | 0x1000);
            private readonly object gate = new object();
            private readonly Stack<Frame> stack = new Stack<Frame>();
            private readonly HashSet<string> skip;
            private readonly bool tally;
            private Dictionary<string, string> groups;
            private long deadline;
            private int busy;

            public long Bytes;
            public long[] Bare = new long[2];
            public Dictionary<string, long[]> Kinds = new Dictionary<string, long[]>();
            public Dictionary<string, long[]> Extensions = new Dictionary<string, long[]>();
            public Dictionary<string, long> One = new Dictionary<string, long>();
            public Dictionary<string, long> Two = new Dictionary<string, long>();

            public StorageWalk(string path, string[] skip, bool tally, string one, string two)
            {
                this.skip = new HashSet<string>(skip, StringComparer.OrdinalIgnoreCase);
                this.tally = tally;
                DirectoryInfo start = new DirectoryInfo(path);
                if ((start.Attributes & FileAttributes.ReparsePoint) == 0)
                {
                    Frame frame = new Frame();
                    frame.Folder = start;
                    // PowerShell hands a $null string over as an empty one.
                    if (!string.IsNullOrEmpty(one)) frame.One = one;
                    if (!string.IsNullOrEmpty(two)) frame.Two = two;
                    stack.Push(frame);
                }
            }

            public bool Done
            {
                get { lock (gate) { return stack.Count == 0; } }
            }

            // Walks until the stop time or the end. Bytes keeps growing over runs; the counts and the
            // tally hold what this run added, for the caller to add to its own totals.
            public bool Run(Stopwatch clock, long stopAtMs, Hashtable kinds, int threads)
            {
                Counts total = new Counts();
                groups = null;
                if (kinds != null)
                {
                    groups = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                    foreach (DictionaryEntry entry in kinds) groups[Convert.ToString(entry.Key)] = Convert.ToString(entry.Value);
                }
                long room = stopAtMs - clock.ElapsedMilliseconds;
                if (room > 0)
                {
                    deadline = Stopwatch.GetTimestamp() + room * (Stopwatch.Frequency / 1000);
                    Frame first = null;
                    lock (gate) { if (stack.Count > 0) first = stack.Pop(); }
                    if (first != null)
                    {
                        List<Frame> found = new List<Frame>();
                        Visit(first, found, total);
                        lock (gate) { foreach (Frame child in found) stack.Push(child); }
                    }
                    Counts[] parts = new Counts[Math.Max(1, threads)];
                    Thread[] workers = new Thread[parts.Length];
                    for (int i = 0; i < parts.Length; i++)
                    {
                        parts[i] = new Counts();
                        workers[i] = new Thread(Work);
                        workers[i].IsBackground = true;
                        workers[i].Start(parts[i]);
                    }
                    for (int i = 0; i < parts.Length; i++)
                    {
                        workers[i].Join();
                        Merge(parts[i], total);
                    }
                }
                Bytes += total.Bytes;
                Bare = total.Bare;
                Kinds = total.Kinds;
                Extensions = total.Extensions;
                One = total.One;
                Two = total.Two;
                return Done;
            }

            private void Work(object state)
            {
                Counts counts = (Counts)state;
                List<Frame> found = new List<Frame>();
                while (true)
                {
                    Frame frame;
                    lock (gate)
                    {
                        while (true)
                        {
                            if (Stopwatch.GetTimestamp() >= deadline) { Monitor.PulseAll(gate); return; }
                            if (stack.Count > 0) break;
                            if (busy == 0) { Monitor.PulseAll(gate); return; }
                            Monitor.Wait(gate, 50);
                        }
                        frame = stack.Pop();
                        busy++;
                    }
                    found.Clear();
                    try { Visit(frame, found, counts); } catch { }
                    lock (gate)
                    {
                        foreach (Frame child in found) stack.Push(child);
                        busy--;
                        Monitor.PulseAll(gate);
                    }
                }
            }

            private void Visit(Frame frame, List<Frame> found, Counts counts)
            {
                FileSystemInfo[] entries;
                try { entries = frame.Folder.GetFileSystemInfos(); } catch { return; }
                long here = 0;
                foreach (FileSystemInfo entry in entries)
                {
                    try
                    {
                        FileAttributes attributes = entry.Attributes;
                        DirectoryInfo folder = entry as DirectoryInfo;
                        if ((attributes & FileAttributes.Directory) != 0)
                        {
                            if (folder == null) continue;
                            if ((attributes & FileAttributes.ReparsePoint) != 0) continue;
                            if (skip.Count > 0) { if (skip.Contains(folder.FullName)) continue; }
                            Frame child = new Frame();
                            child.Folder = folder;
                            child.One = frame.One;
                            child.Two = frame.Two;
                            if (tally) { if (frame.One == null) child.One = folder.Name; else if (frame.Two == null) child.Two = folder.Name; }
                            found.Add(child);
                            continue;
                        }
                        FileInfo file = entry as FileInfo;
                        if (file == null) continue;
                        if ((attributes & CloudOnly) != 0) continue;
                        long length = file.Length;
                        counts.Bytes += length;
                        here += length;
                        if (groups != null) Count(file.Name, length, counts);
                    }
                    catch
                    {
                    }
                }
                if (tally & here > 0 & frame.One != null)
                {
                    Add(counts.One, frame.One, here);
                    if (frame.Two != null) Add(counts.Two, frame.One + "\\" + frame.Two, here);
                }
            }

            private void Count(string name, long length, Counts counts)
            {
                int dot = name.LastIndexOf('.');
                string extension = "";
                if (dot > 0 & dot < name.Length - 1) extension = name.Substring(dot + 1).ToLowerInvariant();
                string kind;
                if (extension.Length == 0) kind = "Other";
                else if (!groups.TryGetValue(extension, out kind)) kind = "Other";
                Add(counts.Kinds, kind, length, 1);
                if (extension.Length == 0)
                {
                    counts.Bare[0] += length;
                    counts.Bare[1] += 1;
                }
                else if (Plain(extension))
                {
                    Add(counts.Extensions, extension, length, 1);
                }
            }

            private static bool Plain(string text)
            {
                if (text.Length > 10) return false;
                foreach (char c in text)
                {
                    if ((c < 'a' | c > 'z') & (c < '0' | c > '9')) return false;
                }
                return true;
            }

            private static void Merge(Counts from, Counts into)
            {
                into.Bytes += from.Bytes;
                into.Bare[0] += from.Bare[0];
                into.Bare[1] += from.Bare[1];
                foreach (KeyValuePair<string, long[]> item in from.Kinds) Add(into.Kinds, item.Key, item.Value[0], item.Value[1]);
                foreach (KeyValuePair<string, long[]> item in from.Extensions) Add(into.Extensions, item.Key, item.Value[0], item.Value[1]);
                foreach (KeyValuePair<string, long> item in from.One) Add(into.One, item.Key, item.Value);
                foreach (KeyValuePair<string, long> item in from.Two) Add(into.Two, item.Key, item.Value);
            }

            private static void Add(Dictionary<string, long[]> table, string key, long bytes, long count)
            {
                long[] slot;
                if (!table.TryGetValue(key, out slot))
                {
                    slot = new long[2];
                    table[key] = slot;
                }
                slot[0] += bytes;
                slot[1] += count;
            }

            private static void Add(Dictionary<string, long> table, string key, long bytes)
            {
                long have;
                table.TryGetValue(key, out have);
                table[key] = have + bytes;
            }
        }
    }
'@
        }
        $script:PccStorageEngine = $true
    } catch {
        $script:PccStorageEngine = $false
    }
    return $script:PccStorageEngine
}

# Starts a walk over a folder, for Measure-StorageWalk to run in one go or in parts. A walk never
# enters a junction or symbolic link, nor a folder whose full path is in $Skip. With $Tally, a folder
# inside the user profile also adds its bytes to the folder directly inside the profile (Tally.One)
# and to the folder inside that one (Tally.Two, keyed "parent\child"); only those two names are kept.
function Get-StorageWalk([string]$Path, $Skip, $Tally) {
    $separators = [char[]]@('\', '/')
    $one = $null
    $two = $null
    if ($Tally) {
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
    $walk = @{ Path = $Path; Bytes = [long]0; Done = $false; Skip = $Skip; Tally = $Tally; Engine = $null; Stack = $null; Threads = [math]::Max(1, [math]::Min(8, [Environment]::ProcessorCount)) }
    if (Test-StorageEngine) {
        $list = [string[]]@()
        if ($null -ne $Skip) { $list = [string[]]@($Skip) }
        $walk.Engine = New-Object Pcc.StorageWalk -ArgumentList $Path, $list, ([bool]$Tally), $one, $two
        $walk.Done = $walk.Engine.Done
        return $walk
    }
    $walk.Stack = New-Object 'System.Collections.Generic.Stack[object[]]'
    $start = New-Object System.IO.DirectoryInfo($Path)
    if (-not ([int]$start.Attributes -band [int][System.IO.FileAttributes]::ReparsePoint)) { $walk.Stack.Push([object[]]@($start, $one, $two)) }
    $walk.Done = $walk.Stack.Count -eq 0
    return $walk
}

# Runs a walk until $StopAtMs on $Clock and says whether it is finished; a later call carries on
# where this one stopped. With time left it always reads at least one folder. It adds up the file
# sizes, skips files that only exist in the cloud, and with $Stats also counts the files by kind and
# extension. File names are looked at in memory only and never kept.
function Measure-StorageWalk($Walk, $Clock, [long]$StopAtMs, $Stats) {
    if ($Walk.Done -or $Clock.ElapsedMilliseconds -ge $StopAtMs) { return $Walk.Done }
    $Tally = $Walk.Tally
    if ($Walk.Engine) {
        $groups = $null
        if ($Stats) { $groups = $Stats.Group }
        $Walk.Done = $Walk.Engine.Run($Clock, $StopAtMs, $groups, $Walk.Threads)
        $Walk.Bytes = $Walk.Engine.Bytes
        if ($Stats) {
            foreach ($item in $Walk.Engine.Kinds.GetEnumerator()) {
                $slot = $Stats.Kind[$item.Key]
                $slot[0] += $item.Value[0]
                $slot[1] += $item.Value[1]
            }
            foreach ($item in $Walk.Engine.Extensions.GetEnumerator()) {
                $slot = $Stats.Ext[$item.Key]
                if ($null -eq $slot) {
                    $slot = @([long]0, [long]0)
                    $Stats.Ext[$item.Key] = $slot
                }
                if ($slot -is [array]) {
                    $slot[0] += $item.Value[0]
                    $slot[1] += $item.Value[1]
                }
            }
            $Stats.Bare[0] += $Walk.Engine.Bare[0]
            $Stats.Bare[1] += $Walk.Engine.Bare[1]
        }
        if ($Tally) {
            foreach ($item in $Walk.Engine.One.GetEnumerator()) { $Tally.One[$item.Key] = [long]$Tally.One[$item.Key] + $item.Value }
            foreach ($item in $Walk.Engine.Two.GetEnumerator()) { $Tally.Two[$item.Key] = [long]$Tally.Two[$item.Key] + $item.Value }
        }
        return $Walk.Done
    }
    $Skip = $Walk.Skip
    $stack = $Walk.Stack
    $bytes = [long]0
    $first = $true
    $directory = [int][System.IO.FileAttributes]::Directory
    $reparse = [int][System.IO.FileAttributes]::ReparsePoint
    $cloudOnly = 0x400000 -bor 0x40000 -bor 0x1000
    while ($stack.Count -gt 0) {
        if (-not $first -and $Clock.ElapsedMilliseconds -ge $StopAtMs) { break }
        $first = $false
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
                            if ($extension.Length -le 10 -and $extension -cmatch '\A[a-z0-9]+\z') { $slot = @([long]0, [long]0) } else { $slot = $false }
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
    $Walk.Bytes += $bytes
    $Walk.Done = $stack.Count -eq 0
    return $Walk.Done
}

# Adds up the file sizes under a folder in one go until $StopAtMs on $Clock, for the space step.
function Measure-StorageTree([string]$Path, $Clock, [long]$StopAtMs) {
    $walk = Get-StorageWalk $Path $null $null
    $done = Measure-StorageWalk $walk $Clock $StopAtMs $null
    return [pscustomobject]@{ Bytes = $walk.Bytes; Capped = -not $done }
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
# The user folder comes first, then Users, Windows, the Program Files folders, ProgramData and the
# other folders at the top of the drive. Each folder gets its share of the clock and hands back what
# it did not use; one that needs more waits, and once every folder had its turn the waiting ones
# carry on where they stopped, in the same order, until the cap. A folder that is still not done is
# listed as missing instead of with a number that would be too small. Returns the lines of the
# "Storage breakdown" section; progress goes to the screen.
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

    Write-Host "Measuring storage (your user folder first, then the rest of the drive), stopping after $StorageCapSeconds seconds at the latest..."
    $profileFull = ''
    if (Test-Path -LiteralPath $profilePath -PathType Container) { $profileFull = [System.IO.Path]::GetFullPath($profilePath).TrimEnd($separators) }
    $tally = $null
    if ($profileFull -ne '') { $tally = @{ Profile = $profileFull; One = @{}; Two = @{} } }

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
    $buckets = New-Object System.Collections.Generic.List[object]
    foreach ($item in $present) {
        $skip = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
        foreach ($path in $item.Skip) { [void]$skip.Add([System.IO.Path]::GetFullPath($path).TrimEnd($separators)) }
        if ($item.Key -eq 'Rest') { foreach ($path in $apart) { [void]$skip.Add($path) } }
        $buckets.Add(@{ Key = $item.Key; Path = $item.Path; Skip = $skip; Weight = [double]$item.Weight; Stats = $stats; Tally = $tally; Drive = $null })
    }

    $known = @('Users', 'Windows', 'Program Files', 'Program Files (x86)', 'ProgramData')
    $knownWeights = @(10, 6, 5, 4, 3)
    $tops = @()
    if ((Test-Path -LiteralPath $rootPath -PathType Container) -and (Test-StorageFixedPath $rootPath)) {
        $tops = @((New-Object System.IO.DirectoryInfo($rootPath)).GetDirectories() | Where-Object {
            -not ([int]$_.Attributes -band [int][System.IO.FileAttributes]::ReparsePoint) -and $_.Name -ne 'System Volume Information' -and $_.Name -ne '$Recycle.Bin'
        } | Sort-Object @{ Expression = { $at = Get-StorageKnownIndex $known $_.Name; if ($at -lt 0) { $known.Count } else { $at } } }, Name)
    }
    $drive = New-Object System.Collections.Generic.List[object]
    foreach ($top in $tops) {
        $at = Get-StorageKnownIndex $known $top.Name
        $weight = [double]1
        if ($at -ge 0) { $weight = [double]$knownWeights[$at] }
        $holds = $false
        $skip = $null
        if ($profileFull -ne '' -and $profileFull.StartsWith($top.FullName.TrimEnd($separators) + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
            $holds = $true
            $skip = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
            [void]$skip.Add($profileFull)
        }
        $clean = $top.Name -replace '[^A-Za-z0-9 ._()+-]', '_'
        $entry = @{ Name = $clean.Substring(0, [math]::Min(40, $clean.Length)); Raw = $top.Name; Bytes = [long]0; Done = $false; Holds = $holds }
        $drive.Add($entry)
        $buckets.Add(@{ Key = $null; Path = $top.FullName; Skip = $skip; Weight = $weight; Stats = $null; Tally = $null; Drive = $entry })
    }

    Write-Host '  Measuring your user folder and the kinds of files in it...'
    $weightLeft = [double]0
    foreach ($bucket in $buckets) { $weightLeft += $bucket.Weight }
    $told = $false
    foreach ($bucket in $buckets) {
        if ($bucket.Drive -and -not $told) {
            Write-Host '  Measuring the biggest folders on the drive...'
            $told = $true
        }
        $slice = Get-StorageSlice $clock $capMs $bucket.Weight $weightLeft
        $weightLeft -= $bucket.Weight
        $bucket.Walk = Get-StorageWalk $bucket.Path $bucket.Skip $bucket.Tally
        if ($slice -gt 0) { [void](Measure-StorageWalk $bucket.Walk $clock ($clock.ElapsedMilliseconds + $slice) $bucket.Stats) }
    }
    $waiting = @($buckets | Where-Object { -not $_.Walk.Done })
    if ($waiting.Count -gt 0 -and $clock.ElapsedMilliseconds -lt $capMs) {
        Write-Host '  Going back to the folders that needed more time...'
        foreach ($bucket in $waiting) { [void](Measure-StorageWalk $bucket.Walk $clock $capMs $bucket.Stats) }
    }
    $got = @{}
    foreach ($bucket in $buckets) {
        if (-not $bucket.Walk.Done) { $capped = $true }
        if ($bucket.Drive) {
            $bucket.Drive.Bytes = $bucket.Walk.Bytes
            $bucket.Drive.Done = $bucket.Walk.Done
        } else {
            $got[$bucket.Key] = $null
            if ($bucket.Walk.Done) { $got[$bucket.Key] = $bucket.Walk.Bytes }
        }
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
# $Clock. Hidden and system items, AppData (with the programs installed per user), Steam game
# libraries (steamapps), junctions, links and files that only exist in the cloud are never entered
# or listed. Names and the folder directly above are kept, never a full path.
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
                    if (($attributes -band $reparse) -or ($atRoot -and $entry.Name -ieq 'AppData') -or $entry.Name -ieq 'steamapps') { continue }
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
        $result = Measure-StorageTree $place.Path $clock ($clock.ElapsedMilliseconds + $slice)
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

# Runs a read in a background job for at most $Seconds so a stuck query cannot hang the scan. Value is
# what it returned, Note says why it could not. The block must not use the helpers of this script.
function Read-CappedJob([scriptblock]$Block, [int]$Seconds = 20, [object[]]$Arguments = @()) {
    $job = $null
    try {
        $job = Start-Job -ScriptBlock $Block -ArgumentList $Arguments
        if (-not (Wait-Job -Job $job -Timeout $Seconds)) {
            return [pscustomobject]@{ Value = $null; Note = "took longer than $Seconds seconds" }
        }
        return [pscustomobject]@{ Value = @(Receive-Job -Job $job -ErrorAction Stop); Note = $null }
    } catch {
        return [pscustomobject]@{ Value = $null; Note = (Explain-Error $_) }
    } finally {
        if ($null -ne $job) { Remove-Job -Job $job -Force -ErrorAction SilentlyContinue }
    }
}

# The lines of the "Wi-Fi signal" section from the text of "netsh wlan show interfaces". Only the
# signal percentage and the band of the connection in use are kept: a value counts only when its
# label is a known one and its value has the exact shape of a percentage, a band or a channel, so
# a network or profile name can never be mistaken for one. Labels in languages not listed here give
# no signal, and the card is then left out. Nothing else (network name, BSSID, profile) is printed.
function Get-WifiQualityLines([string[]]$Netsh) {
    $signal = $null
    $band = $null
    $channel = $null
    foreach ($raw in @($Netsh)) {
        if ($raw -notmatch '^\s{2,}([^:]+?)\s*:\s*(\S.*?)\s*$') { continue }
        $key = $Matches[1]
        $value = $Matches[2]
        if ($null -eq $signal -and $key -match '^(Signal|Signaal|Signalst|Se.al|Segnale|Sinal|Sygna)' -and $value -match '^(\d{1,3})%$' -and [int]$Matches[1] -le 100) {
            $signal = [int]$Matches[1]
        } elseif ($null -eq $band -and $key -match '^(Band|Banda|Bande|Frequenzband)\b' -and $value -match '^(2[.,]4|5|6)\s*GHz$') {
            $band = ($Matches[1] -replace ',', '.') + ' GHz'
        } elseif ($null -eq $channel -and $key -match '^(Channel|Kanaal|Kanal|Canal|Canale|Kana)' -and $value -match '^\d{1,3}$') {
            $channel = [int]$value
        }
    }
    if ($null -eq $signal) { return @('format: 1', 'status: not connected or could not check') }
    if ($null -eq $band -and $null -ne $channel) {
        if ($channel -ge 1 -and $channel -le 14) { $band = '2.4 GHz' } elseif ($channel -ge 32 -and $channel -le 177) { $band = '5 GHz' }
    }
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    $lines.Add("signal-percent: $signal")
    if ($band) { $lines.Add("band: $band") }
    return $lines.ToArray()
}

# "yyyy-MM-dd|milliseconds" rows from the XML of boot performance events (event 100 of the
# Diagnostics-Performance log). An event without a readable BootTime or time stamp gives no row.
function Get-BootRowsFromXml([string[]]$Xml) {
    foreach ($text in @($Xml)) {
        try {
            $record = ([xml]$text).Event
            $data = @($record.EventData.Data) | Where-Object { $_.Name -eq 'BootTime' } | Select-Object -First 1
            $at = ([datetime]::Parse([string]$record.System.TimeCreated.SystemTime, [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::AdjustToUniversal -bor [Globalization.DateTimeStyles]::AssumeUniversal)).ToLocalTime()
            "$($at.ToString('yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture))|$($data.'#text')"
        } catch {
            continue
        }
    }
}

# The lines of the "Boot time" section from "yyyy-MM-dd|milliseconds" rows of the boot performance
# event (newest first). The typical time is the median of up to the five newest usable boots, so one
# slow start after an update does not stand for the machine.
function Get-BootTimeLines([string[]]$Rows, [datetime]$Today) {
    $usable = New-Object System.Collections.Generic.List[object]
    foreach ($row in @($Rows)) {
        if ($row -notmatch '^(\d{4}-\d{2}-\d{2})\|(\d{1,9})$') { continue }
        $seconds = [int][math]::Round([double]$Matches[2] / 1000)
        if ($seconds -ge 1 -and $seconds -le 7200) { $usable.Add([pscustomobject]@{ Date = $Matches[1]; Seconds = $seconds }) }
    }
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    $lines.Add("scanned: $($Today.ToString('yyyy-MM-dd'))")
    if ($usable.Count -eq 0) {
        $lines.Add('boot-samples: 0')
        return $lines.ToArray()
    }
    $recent = @($usable | Select-Object -First 5)
    $sorted = @($recent | ForEach-Object { $_.Seconds } | Sort-Object)
    $middle = [int][math]::Floor($sorted.Count / 2)
    $typical = if ($sorted.Count % 2 -eq 1) { $sorted[$middle] } else { [int][math]::Round(($sorted[$middle - 1] + $sorted[$middle]) / 2) }
    $lines.Add("boot-seconds: $typical")
    $lines.Add("boot-latest-seconds: $($recent[0].Seconds)")
    $lines.Add("boot-latest: $($recent[0].Date)")
    $lines.Add("boot-samples: $($recent.Count)")
    return $lines.ToArray()
}

# The lines of the "Recent app crashes" section from the program names of recent "Application Error"
# events. Names only (no paths, no modules), at most ten, counted case-insensitively. $Capped says
# the read stopped at its limit; $Failure is set when the log could not be read.
function Get-CrashAppLines([string[]]$Names, [datetime]$Today, [int]$Days, [bool]$Capped, $Failure) {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    $lines.Add("scanned: $($Today.ToString('yyyy-MM-dd'))")
    $lines.Add("window-days: $Days")
    if ($Failure) {
        $lines.Add('scan: could not check')
        return $lines.ToArray()
    }
    $clean = @(@($Names) | ForEach-Object { (([string]$_) -replace '[\x00-\x1f\x7f|\\/]', '').Trim() } | Where-Object { $_ -and $_ -ne '.' -and $_ -ne '..' } | ForEach-Object { if ($_.Length -gt 80) { $_.Substring(0, 80) } else { $_ } })
    $lines.Add($(if ($Capped) { 'scan: partial (event limit reached)' } else { 'scan: complete' }))
    $lines.Add("total: $($clean.Count)")
    $clean | Group-Object | Sort-Object @{ Expression = { $_.Count }; Descending = $true }, Name | Select-Object -First 10 | ForEach-Object { $lines.Add("app|$($_.Name)|$($_.Count)") }
    return $lines.ToArray()
}

# Known remote-access tools, matched against program names, Program Files folder names and process
# names. Only the name on the left is ever printed. The app holds the same list of names.
$RemoteTools = @(
    @{ Name = 'TeamViewer'; Pattern = 'teamviewer' }
    @{ Name = 'AnyDesk'; Pattern = 'anydesk' }
    @{ Name = 'UltraViewer'; Pattern = 'ultraviewer' }
    @{ Name = 'RustDesk'; Pattern = 'rustdesk' }
    @{ Name = 'Supremo'; Pattern = '^supremo' }
    @{ Name = 'ScreenConnect'; Pattern = 'screenconnect|connectwise control' }
    @{ Name = 'LogMeIn'; Pattern = 'logmein' }
    @{ Name = 'Splashtop'; Pattern = 'splashtop|^srservice$|^srmanager$' }
    @{ Name = 'AeroAdmin'; Pattern = 'aeroadmin' }
    @{ Name = 'Ammyy Admin'; Pattern = 'ammyy|^aa_v3$' }
    @{ Name = 'Chrome Remote Desktop'; Pattern = 'chrome remote desktop|^remoting_host$' }
    @{ Name = 'Remote Utilities'; Pattern = 'remote utilities|^rutserv$|^rfusclient$' }
    @{ Name = 'Radmin'; Pattern = 'radmin(?! ?vpn)' }
    @{ Name = 'RealVNC'; Pattern = 'realvnc|^vncserver$|^vncviewer$' }
    @{ Name = 'TightVNC'; Pattern = 'tightvnc|^tvnserver$' }
    @{ Name = 'UltraVNC'; Pattern = 'ultravnc|^winvnc$' }
    @{ Name = 'NoMachine'; Pattern = 'nomachine|^nxservice' }
    @{ Name = 'Zoho Assist'; Pattern = 'zoho ?assist' }
    @{ Name = 'DWService'; Pattern = 'dwservice|dwagent' }
    @{ Name = 'HopToDesk'; Pattern = 'hoptodesk' }
    @{ Name = 'GoTo Resolve'; Pattern = 'goto ?resolve|gotoassist' }
    @{ Name = 'BeyondTrust'; Pattern = 'bomgar|beyondtrust remote support' }
    @{ Name = 'Parsec'; Pattern = '^parsecd?$' }
)

# The lines of the "Remote access tools" section from "i|name" (an installed program or a Program
# Files folder) and "r|name" (a running process) rows. Names that match no known tool are dropped
# here and never printed, so this is not an inventory. LogMeIn Hamachi is a VPN, not remote control.
function Get-RemoteToolLines([string[]]$Rows, $Failure) {
    if ($Failure) { return @('format: 1', 'scan: could not check') }
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    $lines.Add('scan: complete')
    $installed = @(@($Rows) | Where-Object { $_ -like 'i|*' -and $_ -notmatch 'hamachi' } | ForEach-Object { $_.Substring(2).Trim() })
    $running = @(@($Rows) | Where-Object { $_ -like 'r|*' -and $_ -notmatch 'hamachi' } | ForEach-Object { $_.Substring(2).Trim() })
    foreach ($tool in $RemoteTools) {
        $states = @()
        if (@($installed | Where-Object { $_ -match $tool.Pattern }).Count -gt 0) { $states += 'installed' }
        if (@($running | Where-Object { $_ -match $tool.Pattern }).Count -gt 0) { $states += 'running' }
        if ($states.Count -gt 0) { $lines.Add("tool|$($tool.Name)|$($states -join ',')") }
    }
    return $lines.ToArray()
}

# Sites whose redirection in the hosts file is a sign of hijacking. The app holds the same list.
$WatchedSites = @(
    'google.com', 'google.nl', 'gmail.com', 'youtube.com', 'microsoft.com', 'live.com', 'outlook.com', 'office.com',
    'microsoftonline.com', 'windowsupdate.com', 'apple.com', 'icloud.com', 'facebook.com', 'paypal.com',
    'ing.nl', 'rabobank.nl', 'abnamro.nl', 'snsbank.nl', 'asnbank.nl', 'regiobank.nl', 'triodos.nl', 'knab.nl',
    'bunq.com', 'digid.nl', 'belastingdienst.nl'
)
$HostsLineLimit = 50000

# Summary lines of the hosts file: how many host names it maps besides the defaults, and which of the
# watched sites it sends elsewhere (redirected) or to nowhere (blocked). No line of the file is printed.
function Get-HostsSummaryLines([string[]]$Lines) {
    $default = @('localhost', 'localhost.localdomain', 'broadcasthost', 'ip6-localhost', 'ip6-loopback')
    $entries = 0
    $redirected = New-Object System.Collections.Generic.List[string]
    $blocked = New-Object System.Collections.Generic.List[string]
    $watched = '(^|\.)(' + (($WatchedSites | ForEach-Object { [regex]::Escape($_) }) -join '|') + ')$'
    $all = @($Lines)
    foreach ($raw in @($all | Select-Object -First $HostsLineLimit)) {
        $parts = (([string]$raw) -replace '#.*$', '').Split([char[]]" `t", [StringSplitOptions]::RemoveEmptyEntries)
        if ($parts.Count -lt 2) { continue }
        $nowhere = $parts[0] -match '^(0\.0\.0\.0|127\.\d{1,3}\.\d{1,3}\.\d{1,3}|::1?|::0)$'
        for ($index = 1; $index -lt $parts.Count; $index++) {
            $name = $parts[$index].ToLowerInvariant().TrimEnd('.')
            if ($default -contains $name) { continue }
            $entries++
            if ($name -notmatch $watched) { continue }
            $site = $Matches[2]
            $list = $redirected
            if ($nowhere) { $list = $blocked }
            if (-not $list.Contains($site)) { $list.Add($site) }
        }
    }
    $status = if ($all.Count -gt $HostsLineLimit) { "hosts: partial (only the first $HostsLineLimit lines were read)" } else { 'hosts: complete' }
    $out = @($status, "hosts-entries: $entries")
    if ($redirected.Count -gt 0) { $out += "hosts-redirected: $($redirected -join ', ')" }
    if ($blocked.Count -gt 0) { $out += "hosts-blocked: $($blocked -join ', ')" }
    return $out
}

# Only the host (and port) of a proxy setting or of the address of a proxy script: no scheme, no
# user name or password, no path. Anything that does not look like a plain host gives nothing.
function Get-ProxyHostText([string]$Value) {
    $text = @(([string]$Value) -split ';' | ForEach-Object { $_.Trim() } | Where-Object { $_ }) | Select-Object -First 1
    if (-not $text) { return $null }
    $text = $text -replace '^[A-Za-z]+=', '' -replace '^[A-Za-z][A-Za-z0-9+.-]*://', ''
    if ($text.Contains('@')) { $text = $text.Substring($text.LastIndexOf('@') + 1) }
    $text = ($text -split '[/?#]')[0]
    if ($text -cmatch '^[A-Za-z0-9._\-\[\]:]{1,100}$') { return $text }
    return $null
}

function Get-ProxyLines($Enable, [string]$Server, [string]$Script) {
    $hostText = if ("$Enable" -eq '1') { Get-ProxyHostText $Server } else { $null }
    $scriptHost = if ($Script) { Get-ProxyHostText $Script } else { $null }
    if ("$Enable" -ne '1' -and -not $Script) { return @('proxy: off') }
    $out = @('proxy: on')
    if ($hostText) { $out += "proxy-host: $hostText" }
    if ($scriptHost) { $out += "proxy-script-host: $scriptHost" }
    return $out
}

function Get-DnsServerLines([string[]]$Addresses) {
    $seen = New-Object System.Collections.Generic.List[string]
    foreach ($address in @($Addresses)) {
        $text = ([string]$address).Trim()
        $parsed = $null
        if ($text -and [System.Net.IPAddress]::TryParse(($text -replace '%.*$', ''), [ref]$parsed) -and -not $seen.Contains($text)) { $seen.Add($text) }
    }
    return @('dns: complete') + @($seen | Select-Object -First 8 | ForEach-Object { "dns-server: $_" })
}

# The lines of the "Antivirus products" section from "productState|displayName" rows of Windows
# Security Center. Bits 12 to 15 of productState are 1 when real-time protection is on. Windows Server
# has no Security Center, which shows as an error about the namespace.
function Get-AntivirusProductLines([string[]]$Rows, $Failure) {
    if ($Failure -match 'namespace') { return @('format: 1', 'status: not available') }
    if ($Failure) { return @('format: 1', 'status: could not check') }
    $products = [ordered]@{}
    foreach ($row in @($Rows)) {
        if ("$row" -notmatch '^(\d{1,10})\|(.*)$') { continue }
        $name = (($Matches[2] -replace '[\x00-\x1f\x7f|]', ' ') -replace '\s+', ' ').Trim()
        if (-not $name) { continue }
        if ($name.Length -gt 60) { $name = $name.Substring(0, 60).Trim() }
        $on = ([int64]$Matches[1] -band 0xF000) -eq 0x1000
        $key = $name.ToLowerInvariant()
        if ($products.Contains($key)) { $products[$key].On = $products[$key].On -or $on } else { $products[$key] = @{ Name = $name; On = $on } }
    }
    $out = @('format: 1', 'status: complete')
    foreach ($product in @($products.Values | Select-Object -First 10)) { $out += "product|$($product.Name)|$(if ($product.On) { 'on' } else { 'off' })" }
    return $out
}

# The lines of the "Devices with problems (Device Manager)" section. Only the friendly name and the
# problem code are kept: hardware ids carry serial numbers, so they are never printed. Disabled (22)
# and absent or unplugged (24, 45) devices are not problems. $Failure is set when the query failed
# or timed out.
function Get-DeviceProblemLines($Devices, $Failure) {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    if ($Failure) {
        $lines.Add('scan: could not check')
        return $lines.ToArray()
    }
    $rows = New-Object System.Collections.Generic.List[string]
    foreach ($device in @($Devices)) {
        if ($null -eq $device) { continue }
        $code = [int]$device.ConfigManagerErrorCode
        if ($code -eq 0 -or $code -eq 22 -or $code -eq 24 -or $code -eq 45) { continue }
        $name = (([string]$device.Name) -replace '[\x00-\x1f\x7f|\\/]', '').Trim()
        if ($name.Length -gt 80) { $name = $name.Substring(0, 80) }
        if (-not $name) { $name = 'Unknown device' }
        $rows.Add("device|$name|$code")
    }
    $lines.Add('scan: complete')
    $lines.Add("total: $($rows.Count)")
    $rows | Select-Object -First 20 | ForEach-Object { $lines.Add($_) }
    return $lines.ToArray()
}

# The lines of the "Activation and BitLocker" section. $LicenceStatus is the LicenseStatus number of
# the Windows licence; only that number is used, never the product key. $Drives holds "letter|code"
# rows of Explorer's System.Volume.BitLockerProtection drive property, which needs no admin rights
# and says only whether a drive is protected; no recovery key or key protector is read. Codes that
# are not known give could not check. $DrivesFailure is set when that read failed or timed out.
function Get-ActivationBitLockerLines($LicenceStatus, $LicenceNote, [string[]]$Drives, $DrivesFailure) {
    $licenceWords = @{ 0 = 'unlicensed'; 1 = 'licensed'; 2 = 'grace period'; 3 = 'grace period'; 4 = 'non-genuine grace period'; 5 = 'notification'; 6 = 'grace period' }
    $driveWords = @{ 0 = 'off'; 1 = 'on'; 2 = 'off'; 3 = 'encrypting'; 4 = 'decrypting'; 5 = 'suspended'; 6 = 'locked'; 8 = 'waiting' }
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    $activation = 'could not check'
    if (-not $LicenceNote -and "$LicenceStatus" -cmatch '^[0-9]\z' -and $licenceWords.ContainsKey([int]"$LicenceStatus")) { $activation = $licenceWords[[int]"$LicenceStatus"] }
    $lines.Add("activation: $activation")
    if ($DrivesFailure) {
        $lines.Add('bitlocker: could not check')
        return $lines.ToArray()
    }
    $lines.Add('bitlocker: complete')
    $seen = New-Object System.Collections.Generic.List[string]
    foreach ($row in @($Drives)) {
        if ("$row" -cnotmatch '^([A-Za-z]:)\|([0-9]{0,2})\z') { continue }
        $letter = $Matches[1].ToUpperInvariant()
        $protection = $Matches[2]
        if ($seen.Contains($letter)) { continue }
        $seen.Add($letter)
        $state = 'could not check'
        if ($protection -ne '' -and $driveWords.ContainsKey([int]$protection)) { $state = $driveWords[[int]$protection] }
        $lines.Add("drive|$letter|$state")
    }
    return $lines.ToArray()
}

# The lines of the "Memory modules" section, so a matching part can be bought: per installed module its
# slot name, size, memory type (from the SMBIOS type code, or the older type code), rated speed and form
# factor. Serial and part numbers are never handed to this function, so they cannot be printed. $Failure
# is set when the query failed or timed out.
function Get-MemoryModuleLines($Modules, $Failure) {
    $types = @{ 20 = 'DDR'; 21 = 'DDR2'; 22 = 'DDR2'; 24 = 'DDR3'; 26 = 'DDR4'; 27 = 'LPDDR'; 28 = 'LPDDR2'; 29 = 'LPDDR3'; 30 = 'LPDDR4'; 34 = 'DDR5'; 35 = 'LPDDR5' }
    $forms = @{ 8 = 'DIMM'; 12 = 'SODIMM' }
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('format: 1')
    if ($Failure) {
        $lines.Add('scan: could not check')
        return $lines.ToArray()
    }
    $lines.Add('scan: complete')
    $listed = 0
    foreach ($module in @($Modules)) {
        $bytes = 0.0
        if ($null -eq $module -or $listed -ge 16) { continue }
        if (-not [double]::TryParse("$($module.Capacity)", [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref]$bytes) -or $bytes -lt 1MB) { continue }
        $slot = (("$($module.DeviceLocator)" -replace '[\x00-\x1f\x7f|]', ' ') -replace '\s+', ' ').Trim()
        if (-not $slot) { $slot = (("$($module.BankLabel)" -replace '[\x00-\x1f\x7f|]', ' ') -replace '\s+', ' ').Trim() }
        if ($slot.Length -gt 40) { $slot = $slot.Substring(0, 40).Trim() }
        $code = 0
        $type = ''
        if ([int]::TryParse("$($module.SMBIOSMemoryType)", [ref]$code) -and $types.ContainsKey($code)) {
            $type = $types[$code]
        } elseif ([int]::TryParse("$($module.MemoryType)", [ref]$code) -and $types.ContainsKey($code)) {
            $type = $types[$code]
        }
        $speed = ''
        foreach ($value in @($module.Speed, $module.ConfiguredClockSpeed)) {
            $number = 0
            if (-not $speed -and [int]::TryParse("$value", [ref]$number) -and $number -gt 0 -and $number -le 20000) { $speed = "$number MHz" }
        }
        $form = ''
        if ([int]::TryParse("$($module.FormFactor)", [ref]$code) -and $forms.ContainsKey($code)) { $form = $forms[$code] }
        $lines.Add("module|$slot|$([math]::Round($bytes / 1MB)) MB|$type|$speed|$form")
        $listed++
    }
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
# when there is a console to ask on. An empty answer there returns the source 'qr': the upload
# is then approved with a QR code once the report is saved.
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
        Write-Host 'NL: typ de scancode uit de app (6 cijfers), of druk op Enter om de upload na de scan met een QR-code te laten goedkeuren.'
        $value = Clean-ScanCode (Read-Host 'EN: scan code from the app, 6 digits, or Enter to approve the upload with a QR code after the scan')
        if ($value -ne '') { return [pscustomobject]@{ Source = 'prompt'; Value = $value } }
        if (-not [Console]::IsOutputRedirected) { return [pscustomobject]@{ Source = 'qr'; Value = '' } }
    }
    return $null
}

function Test-UploadUrl([string]$Value) {
    return $Value -match '^(https://[A-Za-z0-9.-]+|http://(localhost|127\.0\.0\.1))(:[0-9]+)?(/[^\s\\?#]*)?$'
}

# The request body: {"markdown": "..."}, with a "code" first when a scan code is given, or an
# "upload_token" first for the token of a QR approval, as UTF-8 bytes without a byte order mark.
function New-UploadBody([string]$Markdown, [string]$ScanCode, [string]$UploadToken = '') {
    $fields = [ordered]@{}
    if ($UploadToken) { $fields['upload_token'] = $UploadToken } elseif ($ScanCode) { $fields['code'] = $ScanCode }
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
function Send-Report([string]$Markdown, [string]$BaseUrl, [string]$ApiToken, [string]$ScanCode, [string]$UploadToken = '') {
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
            -ContentType 'application/json; charset=utf-8' -Body (New-UploadBody $Markdown $ScanCode $UploadToken) -TimeoutSec 30 -MaximumRedirection 0
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

function Show-UploadHelp {
    Write-Host 'To upload this report: choose Start a scan in the app and run again with -Code (or PC_CHECKUP_CODE set), or create a token with only the Write ability (Settings, API tokens) and set PC_CHECKUP_TOKEN or put a pc-checkup.token file next to this script.'
}

# ---------- QR approval (Enter at the scan code prompt) ----------
# One request to the pairing endpoints. Never throws: Status is the HTTP status (0 without an
# answer), Data the parsed JSON. The secret travels as a bearer token, never in the URL.
function Invoke-PairingRequest([string]$Method, [string]$Uri, [string]$Secret) {
    $result = [pscustomobject]@{ Status = 0; Data = $null }
    $content = $null
    $ProgressPreference = 'SilentlyContinue'
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        $headers = @{ Accept = 'application/json' }
        if ($Secret) { $headers['Authorization'] = "Bearer $Secret" }
        $response = Invoke-WebRequest -UseBasicParsing -Method $Method -Uri $Uri -Headers $headers -TimeoutSec 15 -MaximumRedirection 0
        $result.Status = [int]$response.StatusCode
        $content = $response.Content
    } catch {
        $failed = Read-FailedResponse $_
        if ($failed) {
            $result.Status = $failed.Status
            $content = $failed.Content
        }
    }
    try { if ($content) { $result.Data = ConvertFrom-Json -InputObject ([string]$content) } } catch { $result.Data = $null }
    return $result
}

# True only when every field of a new pairing has the shape the site gives it, so nothing else
# from the answer is ever printed.
function Test-PairingStart($Data) {
    if ($null -eq $Data) { return $false }
    $lines = @($Data.qr)
    if ($lines.Count -lt 10 -or $lines.Count -gt 100) { return $false }
    $blocks = '\A[ #' + [char]0x2580 + [char]0x2584 + [char]0x2588 + ']{1,200}\z'
    foreach ($line in $lines) {
        if ($line -isnot [string] -or $line -cnotmatch $blocks) { return $false }
    }
    return ([string]$Data.id -cmatch '\A[A-Za-z0-9]{24}\z') -and ([string]$Data.secret -cmatch '\A[A-Za-z0-9]{40}\z') -and
        ([string]$Data.check_code -cmatch '\A[A-Z0-9]{4}\z') -and ([string]$Data.url -cmatch '\Ahttps?://[\x21-\x7e]{1,300}\z')
}

# Switches the console to UTF-8 for the half blocks and returns the encoding to switch back to,
# or $null when this console cannot switch: then the ASCII QR code is asked for instead.
function Enable-Utf8Output {
    try {
        $previous = [Console]::OutputEncoding
        [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
        return $previous
    } catch {
        return $null
    }
}

# Prints the QR code dark on light whatever the console colours are. The ASCII version is painted
# with background colours, because # signs alone scan badly. Into a file it is written as it is.
function Show-PairingQr([string[]]$Lines, [bool]$Ascii) {
    $plain = $true
    try { $plain = [Console]::IsOutputRedirected } catch { $plain = $true }
    foreach ($line in $Lines) {
        if ($plain) {
            Write-Host $line
        } elseif (-not $Ascii) {
            Write-Host $line -ForegroundColor Black -BackgroundColor White
        } else {
            foreach ($run in [regex]::Matches($line, '#+| +')) {
                $color = if ($run.Value[0] -eq '#') { 'Black' } else { 'White' }
                Write-Host (' ' * $run.Length) -BackgroundColor $color -NoNewline
            }
            Write-Host ''
        }
    }
}

# True when Ctrl-C was pressed while it arrives as a key. Without a console it never is.
function Test-CancelKey {
    try {
        while ([Console]::KeyAvailable) {
            $key = [Console]::ReadKey($true)
            if ($key.Key -eq [ConsoleKey]::C -and ($key.Modifiers -band [ConsoleModifiers]::Control)) { return $true }
        }
    } catch {
        return $false
    }
    return $false
}

# Asks every 3 seconds, for at most $Seconds, whether the owner decided. The outcome is approved
# (with the upload token), rejected, expired, stopped or timeout. A failed poll is asked again.
# The time is counted both in polls and on the clock, so a slow network cannot stretch it.
function Wait-PairingApproval([string]$BaseUrl, [string]$Id, [string]$Secret, [int]$Seconds) {
    $waited = 0
    $clock = [Diagnostics.Stopwatch]::StartNew()
    while ($waited -lt $Seconds -and $clock.Elapsed.TotalSeconds -lt $Seconds) {
        $answer = Invoke-PairingRequest 'Get' "$BaseUrl/scripts/pair/$Id" $Secret
        $status = ''
        if ($answer.Status -eq 404) { $status = 'expired' } elseif ($answer.Status -eq 200 -and $answer.Data) { $status = [string]$answer.Data.status }
        $token = if ($answer.Data) { [string]$answer.Data.upload_token } else { '' }
        if ($status -eq 'approved' -and $token -cmatch '\A[A-Za-z0-9]{40}\z') { return [pscustomobject]@{ Outcome = 'approved'; Token = $token } }
        if ($status -eq 'rejected') { return [pscustomobject]@{ Outcome = 'rejected'; Token = '' } }
        if ($status -eq 'expired' -or $status -eq 'used') { return [pscustomobject]@{ Outcome = 'expired'; Token = '' } }
        for ($tick = 0; $tick -lt 30; $tick++) {
            if (Test-CancelKey) { return [pscustomobject]@{ Outcome = 'stopped'; Token = '' } }
            Start-Sleep -Milliseconds 100
        }
        $waited += 3
    }
    return [pscustomobject]@{ Outcome = 'timeout'; Token = '' }
}

# Asks the site for a pairing (nothing about this PC is sent), shows its QR code, link and check
# code, and waits for the owner to approve it on a phone. Only then is the report sent, with the
# one-time upload token. Ctrl-C stops the wait and sends nothing.
function Invoke-PairedUpload([string]$Markdown, [string]$BaseUrl) {
    $encoding = Enable-Utf8Output
    $ctrlC = $null
    try {
        $format = if ($null -ne $encoding) { 'half' } else { 'ascii' }
        $start = Invoke-PairingRequest 'Post' "$BaseUrl/scripts/pair?qr=$format" ''
        if ($start.Status -ne 201 -or -not (Test-PairingStart $start.Data)) {
            Write-Host "Upload skipped: could not start a QR approval with $BaseUrl (HTTP $($start.Status))."
            Show-UploadHelp
            return
        }
        $data = $start.Data
        $check = [string]$data.check_code
        Write-Host ''
        Write-Host "NL: Laat de eigenaar deze QR-code scannen met een telefoon (of stuur een foto) en controleer samen dat de code $check is."
        Write-Host "EN: Have the owner scan this QR code with a phone (or send a photo of it) and check together that the code is $check."
        Write-Host ''
        Show-PairingQr @($data.qr) ($format -eq 'ascii')
        if ($null -ne $encoding) {
            [Console]::OutputEncoding = $encoding
            $encoding = $null
        }
        Write-Host ''
        Write-Host "  $([string]$data.url)"
        Write-Host "  Code: $check"
        Write-Host ''
        Write-Host 'NL: Wachten op goedkeuring, hooguit 10 minuten. Ctrl-C stopt zonder iets te versturen.'
        Write-Host 'EN: Waiting for approval, up to 10 minutes. Ctrl-C stops without sending anything.'
        $seconds = 600
        if ([string]$data.expires_in -match '\A[0-9]{1,5}\z' -and [int]$data.expires_in -lt $seconds) { $seconds = [int]$data.expires_in }
        try {
            $ctrlC = [Console]::TreatControlCAsInput
            [Console]::TreatControlCAsInput = $true
        } catch {
            $ctrlC = $null
        }
        $result = Wait-PairingApproval $BaseUrl ([string]$data.id) ([string]$data.secret) $seconds
        Write-Host ''
        switch ($result.Outcome) {
            'approved' {
                Write-Host 'NL: Goedgekeurd, het rapport wordt verstuurd.'
                Write-Host 'EN: Approved, sending the report.'
                Show-UploadResult (Send-Report $Markdown $BaseUrl '' '' $result.Token) $BaseUrl $true
                return
            }
            'stopped' { Write-Host 'NL: Gestopt, er is niets verstuurd.'; Write-Host 'EN: Stopped, nothing was sent.' }
            'rejected' { Write-Host 'NL: De eigenaar heeft de upload afgewezen, er is niets verstuurd.'; Write-Host 'EN: The owner rejected the upload, nothing was sent.' }
            'expired' { Write-Host 'NL: De goedkeuring is verlopen, er is niets verstuurd.'; Write-Host 'EN: The approval request expired, nothing was sent.' }
            default { Write-Host 'NL: Niemand heeft binnen 10 minuten goedgekeurd, er is niets verstuurd.'; Write-Host 'EN: Nobody approved the upload within 10 minutes, nothing was sent.' }
        }
        Show-UploadHelp
    } finally {
        if ($null -ne $encoding) { try { [Console]::OutputEncoding = $encoding } catch { $encoding = $null } }
        if ($null -ne $ctrlC) { try { [Console]::TreatControlCAsInput = $ctrlC } catch { $ctrlC = $null } }
    }
}

# Called once the report is saved: uploads it, or says how to. Never throws.
function Invoke-ReportUpload([string]$Markdown, $Found, $FoundCode, [string]$GivenUrl) {
    if (-not $Found -and -not $FoundCode) {
        Show-UploadHelp
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
        } elseif ($FoundCode.Source -eq 'qr') {
            Invoke-PairedUpload $Markdown $base
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

Write-Host 'NL: Deze scan leest alleen hardware, schijfgebruik, map- en appnamen en beveiligingsinstellingen, wijzigt niets en verstuurt het rapport alleen als u een scancode invoert of de upload met een QR-code laat goedkeuren.'; Write-Host "EN: This scan only reads hardware, disk use, folder and app names and security settings, changes nothing, and uploads the report only if you enter a scan code or have the upload approved with a QR code. $DefaultUrl/privacy"
$found = $null
$foundCode = $null
try { $found = Get-UploadToken $Token $PSScriptRoot } catch { Write-Host "Upload skipped: could not read the token ($(One-Line $_.Exception.Message))." }
if (-not $found) {
    try { $foundCode = Get-ScanCode $Code } catch { Write-Host "Upload skipped: could not read the scan code ($(One-Line $_.Exception.Message))." }
}

function Get-ScriptBanner { "PC Checkup Windows script, version $ScriptVersion" }

function Get-ScriptVersionLine { "- Script version: $ScriptVersion" }

# Asks the site which script version is current (one GET, nothing about this PC) and, when it
# differs from this script, asks y/N. Returns 'current', 'outdated, continued', 'stopped' or
# 'not checked' (switched off with PCC_SKIP_UPDATE_CHECK=1, nobody can answer, the request
# fails or the answer is odd). It never downloads or runs anything.
function Invoke-UpdateCheck([string]$GivenUrl, [bool]$Interactive = ([Environment]::UserInteractive -and -not [Console]::IsInputRedirected)) {
    try {
        if ($env:PCC_SKIP_UPDATE_CHECK -eq '1' -or -not $Interactive) { return 'not checked' }
        if ($ScriptVersion -cnotmatch '\A[0-9a-f]{8}\z') { return 'not checked' }
        $base = $GivenUrl
        if (-not $base) { $base = $env:PC_CHECKUP_URL }
        if (-not $base) { $base = $DefaultUrl }
        $base = ([string]$base).Trim().TrimEnd('/')
        if (-not (Test-UploadUrl $base)) { return 'not checked' }
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        $answer = Invoke-RestMethod -Uri "$base/scripts/version/windows" -TimeoutSec 5 -MaximumRedirection 0
        if ($answer -isnot [string]) { return 'not checked' }
        $current = $answer.Trim()
        if ($current -cnotmatch '\A[0-9a-f]{8}\z') { return 'not checked' }
        if ($current -ceq $ScriptVersion) { return 'current' }
        Write-Host ''
        Write-Host 'Er is een nieuwere versie van dit script beschikbaar.'
        Write-Host 'A newer version of this script is available.'
        Write-Host "Download het hier / Download it here: $base/"
        $reply = ([string](Read-Host 'Toch doorgaan? / Continue anyway? [y/N]')).Trim()
        if ($reply -match '^(y|yes|j|ja)$') { return 'outdated, continued' }
        Write-Host 'Gestopt. Download het nieuwe script en start het opnieuw. / Stopped. Download the new script and run it again.'
        return 'stopped'
    } catch {
        return 'not checked'
    }
}

Write-Host (Get-ScriptBanner)
$updateCheck = Invoke-UpdateCheck $Url
if ($updateCheck -eq 'stopped') { exit 1 }
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

$badDevices = Try-Run { @(Get-CimInstance Win32_PnPEntity -Filter 'ConfigManagerErrorCode <> 0 AND ConfigManagerErrorCode <> 22 AND ConfigManagerErrorCode <> 24 AND ConfigManagerErrorCode <> 45' -OperationTimeoutSec 30 -ErrorAction Stop) }
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
    Get-DeviceProblemLines $badDevices.Value $badDevices.Note
}
Add-Section 'Listening network ports' {
    $names = @{}
    foreach ($p in Get-Process) { $names[$p.Id] = $p.ProcessName }
    Get-NetTCPConnection -State Listen | Sort-Object LocalPort | Select-Object -First 40 LocalAddress, LocalPort, @{ N = 'Process'; E = { $names[[int]$_.OwningProcess] } } | Format-Table -AutoSize
}
Add-Section 'Crashes and unexpected shutdowns (last 30 days)' {
    if ($crashEvents.Value -and @($crashEvents.Value).Count -gt 0) {
        $crashEvents.Value | Select-Object -First 15 TimeCreated, Id, ProviderName | Format-Table -AutoSize
    } elseif ($crashEvents.Note) { $crashEvents.Note } else { '(none)' }
}
Add-Section 'Wi-Fi signal' {
    $read = Read-CappedJob { netsh wlan show interfaces } 20
    if ($read.Note) { 'format: 1'; 'status: could not check' } else { Get-WifiQualityLines $read.Value }
}
Add-Section 'Boot time' {
    $read = Read-CappedJob {
        foreach ($entry in @(Get-WinEvent -FilterHashtable @{ LogName = 'Microsoft-Windows-Diagnostics-Performance/Operational'; Id = 100 } -MaxEvents 10 -ErrorAction Stop)) { $entry.ToXml() }
    } 25
    Get-BootTimeLines $(if ($read.Note) { @() } else { Get-BootRowsFromXml $read.Value }) (Get-Date)
}
Add-Section 'Recent app crashes' {
    $days = 30
    $limit = 500
    $read = Read-CappedJob {
        param($Days, $Limit)
        try {
            $events = @(Get-WinEvent -FilterHashtable @{ LogName = 'Application'; ProviderName = 'Application Error'; Id = 1000; StartTime = (Get-Date).AddDays(-$Days) } -MaxEvents $Limit -ErrorAction Stop)
        } catch {
            if ($_.FullyQualifiedErrorId -like 'NoMatchingEventsFound*') { $events = @() } else { throw }
        }
        foreach ($entry in $events) { "$(@(([xml]$entry.ToXml()).Event.EventData.Data)[0].'#text')" }
    } 25 @($days, $limit)
    Get-CrashAppLines $read.Value (Get-Date) $days (@($read.Value).Count -ge $limit) $read.Note
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
Add-Section 'Remote access tools' {
    $read = Read-CappedJob {
        $keys = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*'
        foreach ($entry in @(Get-ItemProperty -Path $keys -ErrorAction SilentlyContinue)) { if ($entry.DisplayName) { "i|$($entry.DisplayName)" } }
        foreach ($root in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
            if ($root -and (Test-Path -LiteralPath $root)) { foreach ($dir in @(Get-ChildItem -LiteralPath $root -Directory -ErrorAction SilentlyContinue)) { "i|$($dir.Name)" } }
        }
        foreach ($process in @(Get-Process -ErrorAction SilentlyContinue)) { "r|$($process.ProcessName)" }
    } 25
    Get-RemoteToolLines $read.Value $read.Note
}
Add-Section 'Network settings' {
    'format: 1'
    $hosts = Read-CappedJob { param($HostsLimit) Get-Content -LiteralPath "$env:SystemRoot\System32\drivers\etc\hosts" -TotalCount ($HostsLimit + 1) -ErrorAction Stop } 15 @($HostsLineLimit)
    if ($hosts.Note) { 'hosts: could not check' } else { Get-HostsSummaryLines $hosts.Value }
    $proxy = Read-CappedJob {
        $settings = Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Internet Settings' -ErrorAction Stop
        [pscustomobject]@{ Enable = "$($settings.ProxyEnable)"; Server = "$($settings.ProxyServer)"; Script = "$($settings.AutoConfigURL)" }
    } 15
    $settings = @($proxy.Value) | Select-Object -First 1
    if ($proxy.Note -or -not $settings) { 'proxy: could not check' } else { Get-ProxyLines $settings.Enable $settings.Server $settings.Script }
    $dns = Read-CappedJob {
        $connected = @(Get-NetIPInterface -ConnectionState Connected -ErrorAction Stop | ForEach-Object { $_.InterfaceIndex })
        foreach ($entry in @(Get-DnsClientServerAddress -ErrorAction Stop)) { if ($connected -contains $entry.InterfaceIndex) { $entry.ServerAddresses } }
    } 20
    if ($dns.Note) { 'dns: could not check' } else { Get-DnsServerLines $dns.Value }
}
Add-Section 'Antivirus products' {
    $read = Read-CappedJob {
        foreach ($product in @(Get-CimInstance -Namespace root\SecurityCenter2 -ClassName AntiVirusProduct -OperationTimeoutSec 15 -ErrorAction Stop)) { "$($product.productState)|$($product.displayName)" }
    } 20
    Get-AntivirusProductLines $read.Value $read.Note
}
Add-Section 'Activation and BitLocker' {
    $drives = Read-CappedJob {
        $shell = New-Object -ComObject Shell.Application
        foreach ($drive in @([System.IO.DriveInfo]::GetDrives() | Where-Object { $_.DriveType -eq 'Fixed' })) {
            $letter = $drive.Name.TrimEnd('\')
            $protection = $null
            try { $protection = $shell.NameSpace("$letter\").Self.ExtendedProperty('System.Volume.BitLockerProtection') } catch { $protection = $null }
            "$letter|$protection"
        }
    } 20
    Get-ActivationBitLockerLines $licence.Value.LicenseStatus $licence.Note $drives.Value $drives.Note
}
Add-Section 'Memory modules' {
    $read = Read-CappedJob {
        foreach ($module in @(Get-CimInstance Win32_PhysicalMemory -OperationTimeoutSec 15 -ErrorAction Stop)) {
            [pscustomobject]@{
                Capacity = $module.Capacity; Speed = $module.Speed; ConfiguredClockSpeed = $module.ConfiguredClockSpeed
                SMBIOSMemoryType = $module.SMBIOSMemoryType; MemoryType = $module.MemoryType; FormFactor = $module.FormFactor
                DeviceLocator = $module.DeviceLocator; BankLabel = $module.BankLabel
            }
        }
    } 25
    Get-MemoryModuleLines $read.Value $read.Note
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
$lines.Add("- Update check: $updateCheck")
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
