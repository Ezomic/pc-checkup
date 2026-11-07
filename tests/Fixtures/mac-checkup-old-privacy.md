# Mac checkup: Test-MacBook-Pro

This report comes from one scan that ran once, only read information, and installed and changed nothing on this Mac. Changes happen only in the optional --fix mode, one y/N question at a time, and are listed under Fix log.

- Format: 1
- Script version: 8294e3c4
- Update check: current
- OS: macOS
- Device type: laptop
- Generated: Thu Nov  5 14:02:17 CET 2026
- Serial: C02OLDPRIV01
- Hardware UUID: 7D3A1C52-0B4E-4F7A-9C21-5E8F00A1B2C3
- Model: MacBook Pro (MacBookPro16,2)
- Chip/CPU: Quad-Core Intel Core i5
- Memory: 16 GB
- macOS: 14.7.1 (23H222)
- Battery: 612 cycles, condition Normal, max capacity 84%
- Free disk: 23%
- RustDesk ID: not found (RustDesk is not installed, or its settings file holds no readable ID)
- Run as administrator: no, sections that need sudo say so
- Warranty and coverage lookup: https://checkcoverage.apple.com (serial above)

## Things to look at

- Custom admin-trusted certificates are installed (see Trusted certificates section).
- Not restarted for 41 days.

## Hardware overview

```
Hardware:

    Hardware Overview:

      Model Name: MacBook Pro
      Model Identifier: MacBookPro16,2
      Processor Name: Quad-Core Intel Core i5
      Processor Speed: 2 GHz
      Number of Processors: 1
      Total Number of Cores: 4
      L2 Cache (per Core): 512 KB
      L3 Cache: 6 MB
      Hyper-Threading Technology: Enabled
      Memory: 16 GB
      System Firmware Version: 2075.100.3.0.3
      OS Loader Version: 582~2170
      SMC Version (system): 2.17f10
      Serial Number (system): C02OLDPRIV01
      Hardware UUID: 7D3A1C52-0B4E-4F7A-9C21-5E8F00A1B2C3
      Activation Lock Status: Disabled
```

## macOS version

```
ProductName:		macOS
ProductVersion:		14.7.1
BuildVersion:		23H222
Darwin Test-MacBook-Pro.local 23.6.0 Darwin Kernel Version 23.6.0: Thu Sep 12 23:35:29 PDT 2024; root:xnu-10063.141.1.701.1~1/RELEASE_X86_64 x86_64
14:02  up 41 days,  2:11, 2 users, load averages: 2.31 2.07 1.98
```

## Model identifier and chip

```
MacBookPro16,2
Intel(R) Core(TM) i5-1038NG7 CPU @ 2.00GHz
8
16 GB RAM
```

## Battery and power

```
Power:

    Battery Information:

      Model Information:
          Manufacturer: SMP
          Device Name: bq20z451
          Pack Lot Code: 0
          PCB Lot Code: 0
          Firmware Version: 1001
          Hardware Revision: 1
          Cell Revision: 3017
      Charge Information:
          The battery's charge is below the warning level: No
          Fully Charged: No
          Charging: No
          State of Charge (%): 71
      Health Information:
          Cycle Count: 612
          Condition: Normal
          Maximum Capacity: 84%
```

## Charger

```
No adapter attached.
Now drawing from 'Battery Power'
 -InternalBattery-0 (id=4653155)	71%; discharging; 4:12 remaining present: true
```

## Storage (boot volume)

```
   Device Node:               /dev/disk1s5s1
   Volume Name:               Macintosh HD
   Container Total Space:     499.96 GB (499963174912 Bytes) (exactly 976490576 512-Byte-Units)
   Container Free Space:      114.86 GB (114861342720 Bytes) (exactly 224338560 512-Byte-Units)
   Solid State:               Yes
   SMART Status:              Verified
   File System Personality:   APFS
   Protocol:                  PCI-Express
   Device / Media Name:       APPLE SSD AP0512N
```

## Disk usage

```
Filesystem        Size    Used   Avail Capacity iused     ifree %iused  Mounted on
/dev/disk1s5s1   466Gi   9.8Gi   107Gi     9%  404167 1121651240    0%   /
devfs            198Ki   198Ki     0Bi   100%     688         0  100%   /dev
/dev/disk1s4     466Gi   2.0Gi   107Gi     2%       2 1121651240    0%   /System/Volumes/VM
/dev/disk1s2     466Gi   360Mi   107Gi     1%     856 1121651240    0%   /System/Volumes/Preboot
/dev/disk1s1     466Gi   344Gi   107Gi    77% 2307551 1121651240    0%   /System/Volumes/Data
```

## Space you can get back

```
scan: complete
seconds: 21
limit: 60
format: 1
reclaim|trash|2516582|112
reclaim|caches|4718592|1904
reclaim|installers|3355443|6
reclaim|backups|0|0
reclaim|derived|0|0
snapshots|2
file|Holiday 2023.mov|9437184|Videos|Movies
file|macOS Sonoma installer.dmg|13631488|Archives|Downloads
file|Scans archive.zip|2097152|Archives|Documents
```

## Apps you may not need

```
scan: complete
seconds: 12
limit: 45
format: 1
scanned: 2026-11-05
app|GarageBand|2097152|2021-03-14
app|iMovie|3145728|2022-07-01
```

## Memory pressure

```
The system has 17179869184 (1048576 pages with a page size of 16384).

Stats:
Pages free: 61344
Pages purgeable: 18210
Pages purged: 9811253

Swap I/O:
Swapins: 1023
Swapouts: 4012

System-wide memory free percentage: 47%
```

## Top processes by CPU

```
  PID  %CPU %MEM COMM
  612  14.2  2.1 WindowServer
 1880   6.8  4.4 Google Chrome
  401   2.0  0.6 TeamViewer
  133   1.1  0.3 mds_stores
```

## Performance: load, swap, memory, throttling

```
vm.loadavg: { 2.31 2.07 1.98 }
vm.swapusage: total = 2048.00M  used = 611.25M  free = 1436.75M  (encrypted)
Note: No thermal warning level has been recorded
Note: No performance warning level has been recorded
Note: No CPU power status has been recorded
 lowpowermode         0
```

## Graphics and displays

```
Graphics/Displays:

    Intel Iris Plus Graphics:

      Chipset Model: Intel Iris Plus Graphics
      Type: GPU
      Bus: Built-In
      VRAM (Dynamic, Max): 1536 MB
      Vendor: Intel
      Displays:
        Color LCD:
          Display Type: Built-In Retina LCD
          Resolution: 2560 x 1600 Retina
```

## Network interfaces

```

Hardware Port: Wi-Fi
Device: en0
Ethernet Address: a4:83:e7:12:34:56

Hardware Port: Thunderbolt Bridge
Device: bridge0
Ethernet Address: 82:3b:9c:00:00:01

VLAN Configurations
===================
```

## Wi-Fi

```
Wi-Fi:

      Software Versions:
          CoreWLAN: 16.0 (1657)
          CoreWLANKit: 16.0 (1657)
          Menu Extra: 17.0 (1728)
          System Information: 15.0 (1502)
          IO80211_Family: 12.0 (1200.13.1)
          Diagnostics: 11.0 (1163)
          AirPort Utility: 6.3.9 (639.19)
      Interfaces:
        en0:
          Card Type: Wi-Fi  (0x14E4, 0x7BF)
          Firmware Version: Broadcom BCM43xx 1.0 (7.77.111.1 AirPortDriverBrcmNIC-1772.1)
          MAC Address: a4:83:e7:12:34:56
          Locale: ETSI
          Country Code: NL
          Supported PHY Modes: 802.11 a/b/g/n/ac
          Supported Channels: 1 (2GHz), 2 (2GHz), 3 (2GHz), 36 (5GHz), 40 (5GHz), 44 (5GHz)
          Wake On Wireless: Supported
          AirDrop: Supported
          Auto Unlock: Supported
          Status: Connected
          Current Network Information:
            Ziggo4471920:
              PHY Mode: 802.11ac
              Channel: 44 (5GHz, 80MHz)
              Country Code: NL
              Network Type: Infrastructure
              Security: WPA2 Personal
              Signal / Noise: -58 dBm / -92 dBm
              Transmit Rate: 585
              MCS Index: 7
          Other Local Wi-Fi Networks:
            Neighbour-Bakker:
              PHY Mode: 802.11
              Channel: 6 (2GHz, 20MHz)
              Network Type: Infrastructure
              Security: WPA2 Personal
              Signal / Noise: -81 dBm / -94 dBm
```

## Wi-Fi signal

```
format: 1
signal-dbm: -58
band: 5 GHz
```

## Bluetooth

```
Bluetooth:

      Bluetooth Controller:
          State: On
          Chipset: BCM_4364B3
          Discoverable: Off
```

## Security: FileVault, SIP, Gatekeeper, firewall

```
FileVault is On.
System Integrity Protection status: enabled.
assessments enabled
Firewall is enabled. (State = 1)
Firewall stealth mode is off
```

## Security: secure boot and firmware

```
auto-boot: true
prev-lang:kbd: nl:0
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

## Firmware password (Intel only)

```
Password Enabled: No
```

## Backup status

```
Time Machine: set up, last backup 2026-11-02
```

## Backup freshness

```
format: 1
scanned: 2026-11-05
timemachine: set up
timemachine-last: 2026-11-02
icloud-folders: Desktop, Documents
```

## Remote exposure

```
Remote Login (SSH): off
Screen Sharing: off
Automatic login: off
```

## Software update age

```
Newest installed macOS update: 2026-10-28 (8 days ago)
```

## Administrator accounts

```
Administrator accounts: 1
```

## User accounts

```
<user>
daemon
nobody
root

Admin group members:
GroupMembership: root <user>

Console user:
<user>
```

## Software updates

```
Software Update Tool

Finding available software
No new software available.
```

## Startup items: LaunchAgents and LaunchDaemons

```
/Library/LaunchAgents:
com.teamviewer.teamviewer.plist
com.teamviewer.teamviewer_desktop.plist

/Library/LaunchDaemons:
com.teamviewer.Helper.plist
com.teamviewer.teamviewer_service.plist

/Users/<user>/Library/LaunchAgents:
com.google.keystone.agent.plist
```

## Login items

```
TeamViewer, Spotify
```

## Cron jobs

```
crontab: no crontab for <user>
```

## Listening network ports

```
COMMAND     PID   USER   FD   TYPE             DEVICE SIZE/OFF NODE NAME
rapportd    612 <user>    9u  IPv4 0x5a1b2c3d4e5f6071      0t0  TCP *:49152 (LISTEN)
TeamViewe   401 <user>   12u  IPv4 0x5a1b2c3d4e5f6072      0t0  TCP 127.0.0.1:5939 (LISTEN)
```

## DNS and proxy

```
DNS configuration

resolver #1
  search domain[0] : fritz.box
  nameserver[0] : 192.168.178.1
  if_index : 6 (en0)
  flags    : Request A records
  reach    : 0x00020002 (Reachable,Directly Reachable Address)

resolver #2
  domain   : local
  options  : mdns
  timeout  : 5
  flags    : Request A records
  reach    : 0x00000000 (Not Reachable)
  order    : 300000
<dictionary> {
  ExceptionsList : <array> {
    0 : *.local
    1 : 169.254/16
  }
  FTPPassive : 1
  HTTPEnable : 0
  HTTPSEnable : 0
}
```

## Hosts file (non-default lines)

```
127.0.0.1	localhost
255.255.255.255	broadcasthost
::1             localhost
192.168.178.20	nas.bakker-thuis.lan
127.0.0.1	activate.example-vendor.com
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
hosts-entries: 2
proxy: off
dns: complete
dns-server: 192.168.178.1
```

## Kernel panic reports

```
ls: /Library/Logs/DiagnosticReports/*.panic: No such file or directory
total 0
```

## Recent app crashes

```
format: 1
scanned: 2026-11-05
window-days: 30
scan: complete
total: 6
app|Google Chrome|3
app|zoom.us|2
app|Microsoft Word|1
```

## Recent shutdown causes

```
Filtering the log data using "eventMessage CONTAINS "Previous shutdown cause""
Timestamp                       Thread     Type        Activity             PID    TTL

(last 7 days, capped at 20 seconds)
```

## Time Machine

```
====================================================
Name          : Backup Bakker
Kind          : Local
Mount Point   : /Volumes/Backup Bakker
ID            : 0E1D2C3B-4A59-6877-8695-A4B3C2D1E0F9
/Volumes/Backup Bakker/Backups.backupdb/Test-MacBook-Pro/2026-11-02-201455
```

## Privacy permissions (TCC), needs Full Disk Access for Terminal

```
== /Users/<user>/Library/Application Support/com.apple.TCC/TCC.db
kTCCServiceMicrophone|us.zoom.xos|allowed
kTCCServiceScreenCapture|com.teamviewer.TeamViewer|allowed
== /Library/Application Support/com.apple.TCC/TCC.db
kTCCServiceAccessibility|com.teamviewer.TeamViewer|allowed
kTCCServiceSystemPolicyAllFiles|com.apple.Terminal|allowed

If you see an authorization error, grant Terminal Full Disk Access in System Settings > Privacy & Security and re-run.
```

## Trusted certificates: custom trust settings

```
-- admin
Cert 0: Contoso Inspection Root CA
-- system
SecTrustSettingsCopyCertificates: No Trust Settings were found.
-- user
SecTrustSettingsCopyCertificates: No Trust Settings were found.
```

## Certificates in System keychain

```
    "labl"<blob>="Contoso Inspection Root CA"
    "labl"<blob>="com.apple.kerberos.kdc"
    "labl"<blob>="com.apple.systemdefault"
```

## Browser extensions: Chrome

```
aapocclcgogkmnckokdopfmhonfmgoek  Slides
ghbmnnjooekpmoecnnnilnnbdlolhkhi  Google Docs Offline
```

## Browser extensions: Firefox

```
```

## Browser extensions: Safari

```
     com.apple.SafariTechnologyPreview.extension(1.0)
```

## Saved Wi-Fi networks and VPNs

```
Preferred networks on en0:
	Ziggo4471920
	Bakker-Gasten
	KPN Fon
	Hotel-Amsterdam-Guest

Available network connection services in the current set (*=enabled):
* (Disconnected)   5A1F2B3C-0000-4000-8000-000000000001 PPP --> L2TP       "Contoso Work VPN"               [PPP/L2TP]
```

## SSD wear (smartctl, optional)

```
smartctl not installed. Optional: brew install smartmontools, then re-run.
```

## Intel apps running under Rosetta (Apple silicon only)

```
Intel Mac, not applicable
```

## Reboot history and boot time

```
reboot    ~                         Thu Sep 24 11:51
wtmp begins Thu Sep 24 11:51
kern.boottime: { sec = 1790243460, usec = 412001 } Thu Sep 24 11:51:00 2026
```

## Fans and temperatures (needs sudo)

```
Run with sudo to include this section.
```

## Locale and time

```
Thu Nov  5 14:04:51 CET 2026
Time Zone: Europe/Amsterdam
(
    "nl-NL",
    "en-NL"
)
```

## Developer tools

```
xcode-select: error: unable to get active developer directory, use `sudo xcode-select --switch path/to/Xcode.app` to set the path to a valid Xcode.app.
git version 2.39.5 (Apple Git-154)
Python 3.9.6
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
