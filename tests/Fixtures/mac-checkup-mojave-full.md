# Mac checkup: Test-MacBook-Air

This report comes from one scan that ran once, only read information, and installed and changed nothing on this Mac.

- Format: 1
- Script version: 0c4e9a17
- Update check: current
- OS: macOS
- Device type: laptop
- Generated: Mon Nov  9 19:40:12 CET 2026
- Serial: TESTMOJAVE01
- Hardware UUID: 1A2B3C4D-0000-4000-8000-00000000AB12
- Model: MacBook Air (MacBookAir7,2)
- Chip/CPU: Intel Core i5
- Memory: 8 GB
- macOS: 10.14.6 (18G9323)
- Battery: 391 cycles, condition Normal, max capacity n/a
- Free disk: 11%
- RustDesk ID: not found
- Run as administrator: no, sections that need sudo say so

## Things to look at

- macOS 10.14.6 is old and likely out of security support.
- FileVault is OFF.
- Application firewall is off.
- Only about 11% of disk is free.
- No macOS update has been installed for 1748 days (newest: 2022-01-26).

## Hardware overview

```
Hardware:

    Hardware Overview:

      Model Name: MacBook Air
      Model Identifier: MacBookAir7,2
      Processor Name: Intel Core i5
      Processor Speed: 1,8 GHz
      Number of Processors: 1
      Total Number of Cores: 2
      L2 Cache (per Core): 256 KB
      L3 Cache: 3 MB
      Hyper-Threading Technology: Enabled
      Memory: 8 GB
      Boot ROM Version: 427.0.0.0.0
      SMC Version (system): 2.27f2
      Serial Number (system): TESTMOJAVE01
      Hardware UUID: 1A2B3C4D-0000-4000-8000-00000000AB12
      Activation Lock Status: Disabled
```

## macOS version

```
ProductName:	Mac OS X
ProductVersion:	10.14.6
BuildVersion:	18G9323
Darwin Test-MacBook-Air.local 18.7.0 Darwin Kernel Version 18.7.0: Tue Jun 22 19:37:08 PDT 2021; root:xnu-4903.278.70~1/RELEASE_X86_64 x86_64
19:40  up 3 days,  4:02, 2 users, load averages: 1,92 1,71 1,60
```

## Model identifier and chip

```
MacBookAir7,2
Intel(R) Core(TM) i5-5350U CPU @ 1.80GHz
4
8 GB RAM
```

## Battery and power

```
Power:

    Battery Information:

      Model Information:
          Manufacturer: SMP
          Device Name: bq20z451
          Firmware Version: 801
          Hardware Revision: 1
          Cell Revision: 2100
      Charge Information:
          Charge Remaining (mAh): 3402
          Fully Charged: No
          Charging: No
          Full Charge Capacity (mAh): 5874
      Health Information:
          Cycle Count: 391
          Condition: Normal
```

## Battery raw (ioreg)

```
      "AppleRawMaxCapacity" = 5874
      "MaxCapacity" = 5874
      "CycleCount" = 391
      "DesignCapacity" = 7150
      "Temperature" = 3011
```

## Storage (boot volume)

```
   Device Node:               /dev/disk1s1
   Volume Name:               Macintosh HD
   Container Total Space:     121.1 GB (121123069952 Bytes) (exactly 236568496 512-Byte-Units)
   Container Free Space:      13.9 GB (13925744640 Bytes) (exactly 27198720 512-Byte-Units)
   Solid State:               Yes
   SMART Status:              Verified
   File System Personality:   APFS
   Protocol:                  PCI
   Device / Media Name:       APPLE SSD SM0128G
```

## Disk usage

```
Filesystem      Size   Used  Avail Capacity iused               ifree %iused  Mounted on
/dev/disk1s1   113Gi   97Gi   13Gi    88%  930118 9223372036853845689    0%   /
devfs          184Ki  184Ki    0Bi   100%     636                   0  100%   /dev
/dev/disk1s4   113Gi  2.0Gi   13Gi    14%       2 9223372036854775805    0%   /private/var/vm
map -hosts       0Bi    0Bi    0Bi   100%       0                   0  100%   /net
map auto_home    0Bi    0Bi    0Bi   100%       0                   0  100%   /home
/dev/disk1s3   113Gi  487Mi   13Gi     4%      36 9223372036854775771    0%   /Volumes/Recovery
```

## Storage breakdown

```
scan: complete
seconds: 79
limit: 150
format: 2
root|Users|52428800
root|Applications|13631488
root|System|7340032
root|Library|4404019
root|private|3460300
root|usr|460000
roots_total|81724639
home|52428800
folder|Downloads|251658
folder|Documents|12
folder|Desktop|2044723
folder|Pictures|352321
folder|Movies|8
folder|Music|20971
folder|Library|8650752
folder|Applications|41180364
library|Application Support|6396313
library|Caches|1782579
library|Containers|251658
library|Group Containers|136314
library|Mobile Documents|2048
library|Logs|8912
home|Applications|41180364
home|Library|8650752
home|Desktop|2044723
home|Pictures|352321
home|Downloads|251658
home|Music|20971
home|.Trash|424
sub|Applications|Racing Game.app|24117248
sub|Applications|Racing Game Packs|17063116
sub|Library|Application Support|6396313
sub|Library|Caches|1782579
sub|Library|Containers|251658
missing|library/Mail
missing|library/Messages
missing|library/Safari
type|Photos|389120|11208
type|Videos|210000|7
type|Music|111000|51
type|Documents|214000|506
type|Archives|290000|15
type|Apps|256|1
type|Other|51300000|76500
noext|4360000|63000
ext|package|40990000|2480
ext|file|2790000|3120
ext|png|303000|6290
ext|zip|289000|14
ext|db|219000|232
ext|mp3|108000|28
ext|pdf|93000|266
ext|jpg|49000|4740
```

## Space you can get back

```
scan: complete
seconds: 1
limit: 60
format: 1
reclaim|trash|424|5
reclaim|caches|1782579|98
unreadable|backups
snapshots|0
file|GameData0.package|1590000|Other|Pack01
file|GameData0.package|1508000|Other|Pack08
file|GameData1.package|1444000|Other|Pack03
file|GameData1.package|1442000|Other|Pack06
file|GameData0.package|1342000|Other|Pack07
```

## Apps you may not need

```
scan: complete
seconds: 7
limit: 45
format: 1
scanned: 2026-11-09
app|Racing Game|24117248|2024-11-18
app|Mail Client|2139068|2024-10-02
app|Spreadsheet App|1163392|2022-01-06
app|Cloud Drive|1154588|unknown
```

## Memory pressure

```
The system has 8589934592 (2097152 pages with a page size of 4096).

Stats:
Pages free: 40122
Pages purgeable: 9120
Pages purged: 1203311

Swap I/O:
Swapins: 20311
Swapouts: 40122

System-wide memory free percentage: 38%
```

## Top processes by CPU

```
  PID  %CPU %MEM COMM
  382  97,0  1,2 Spotify
  254  16,5  0,1 tccd
 2426  14,2  0,2 trustd
 1510   3,7  0,7 Terminal
   66   2,0  0,6 mds
```

## Performance: load, swap, memory, throttling

```
vm.loadavg: { 1,92 1,71 1,60 }
vm.swapusage: total = 1024,00M  used = 611,25M  free = 412,75M  (encrypted)
Note: No thermal warning level has been recorded
Note: No performance warning level has been recorded
Note: No CPU power status has been recorded
```

## Wi-Fi signal

```
format: 1
signal-dbm: -61
band: 5 GHz
```

## Security: FileVault, SIP, Gatekeeper, firewall

```
FileVault is Off.
System Integrity Protection status: enabled.
assessments enabled
Firewall is disabled. (State = 0)
Firewall stealth mode is off
```

## Activation Lock and Find My

```
      Activation Lock Status: Disabled
Find My Mac: on
```

## MDM and enrolment

```
Enrolled via DEP: No
MDM enrollment: No

There are no configuration profiles installed
```

## Backup status

```
Time Machine: set up, last backup 2026-11-04
```

## Backup freshness

```
format: 1
scanned: 2026-11-09
timemachine: set up
timemachine-last: 2026-11-04
icloud-folders: none
```

## Remote exposure

```
Remote Login (SSH): off
Screen Sharing: off
Automatic login: off
```

## Software update age

```
Newest installed macOS update: 2022-01-26 (1748 days ago)
```

## Administrator accounts

```
Administrator accounts: 1
```

## Software updates

```
No new software available.
Software Update Tool

Finding available software
```

## Remote access tools

```
format: 1
scan: complete
tool|TeamViewer|installed,running
```

## Network settings

```
format: 1
hosts: complete
hosts-entries: 0
proxy: off
dns: complete
dns-server: 192.168.1.1
```

## Kernel panic reports

```
ls: /Library/Logs/DiagnosticReports/*.panic: No such file or directory
total 0
```

## Recent app crashes

```
format: 1
scanned: 2026-11-09
window-days: 30
scan: complete
total: 0
```

## Reboot history and boot time

```
reboot    ~                         Fri Nov  6 15:38
wtmp begins Fri Nov  6 15:38
kern.boottime: { sec = 1793630280, usec = 120004 } Fri Nov  6 15:38:00 2026
```

## Developer tools

```
xcode-select: error: unable to get active developer directory, use `sudo xcode-select --switch path/to/Xcode.app` to set one (or see `man xcode-select`)
```

## Manual checklist (cannot be done by script)

Hardware
- [ ] Apple Diagnostics passed (Apple silicon: hold power until options appear, then Cmd+D. Intel: hold D at startup).
- [ ] Look up the serial at checkcoverage.apple.com (coverage, purchase date, AppleCare).
- [ ] System Settings > General > About: check repair history and any non-genuine parts warnings.
- [ ] Keyboard (every key), trackpad click, Touch ID, speakers, microphone, camera.
- [ ] Every port: charging, USB-C/Thunderbolt, headphone jack, SD slot.
- [ ] Screen: dead pixels, bright spots, burn-in (view solid white, black and red).
- [ ] Case: dents, swollen battery (trackpad lifting or case bulging), loose hinge, liquid damage.
- [ ] Stress test: run a video or heavy task for 10 minutes. Watch for fan noise, heat, throttling or shutdowns.

Handover and cleanup
- [ ] Activation Lock is off and no previous owner's Apple ID is signed in.
- [ ] No old user accounts, saved passwords, mail accounts or personal files left behind.
- [ ] Backup exists (Time Machine or other) before changing anything.
- [ ] Paid apps tied to the previous owner (Office, Adobe) are reassigned.
- [ ] For a clean start: sign out of iCloud, then System Settings > General > Transfer or Reset > Erase All Content and Settings.
