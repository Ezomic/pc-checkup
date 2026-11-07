# Windows checkup: TEST-OLDHOSTS

This report comes from one scan that ran once, only read information, and installed and changed nothing on this PC.

- Format: 1
- Script version: a4c32056
- Update check: current
- OS: Windows
- Device type: laptop
- Generated: 2026-11-06 10:41
- Serial: 5CD0OLDHST1
- Hardware UUID: 3E1F7A20-5B6C-11EE-8C99-0242AC120002
- Model: HP EliteBook 840 G7
- Chip/CPU: Intel(R) Core(TM) i5-10310U CPU @ 1.70GHz (4 cores, 8 threads)
- Memory: 16 GB
- Windows: Windows 11 Pro 24H2 (build 26100.7171)
- Battery: 214 cycles, 88% of design capacity
- Free disk: 34%
- RustDesk ID: not found (RustDesk is not installed, or its settings file holds no readable ID)
- Secure Boot: on
- BitLocker: on
- Run as administrator: yes

## Things to look at

- Security, management or remote access agents running (can slow a PC or give someone else access): AnyDesk

## Windows version

```
Caption        : Microsoft Windows 11 Pro
Version        : 10.0.26100
BuildNumber    : 26100
OSArchitecture : 64-bit
InstallDate    : 3/14/2025 9:12:40 AM
LastBootUpTime : 11/5/2026 8:02:11 AM
```

## CPU and memory

```
Name                                     NumberOfCores NumberOfLogicalProcessors MaxClockSpeed
----                                     ------------- ------------------------- -------------
Intel(R) Core(TM) i5-10310U CPU @ 1.70GHz             4                         8          2208

Total memory: 16 GB
```

## Storage (physical disks)

```
FriendlyName                 MediaType BusType SizeGB HealthStatus OperationalStatus
------------                 --------- ------- ------ ------------ -----------------
SAMSUNG MZVLB512HBJQ-000H1   SSD       NVMe       477 Healthy      OK
```

## Storage (volumes and free space)

```
DeviceID VolumeName FileSystem SizeGB FreeGB Free%
-------- ---------- ---------- ------ ------ -----
C:       Windows    NTFS          476    162    34
```

## Space you can get back

```
scan: complete
seconds: 17
limit: 60
format: 1
reclaim|recycle|1048576|23
reclaim|temp|2202009|1840
reclaim|systemtemp|524288|61
reclaim|updates|3145728|22
reclaim|installers|419430|3
file|Teams meeting recording.mp4|3355443|Videos|Videos
file|OfficeSetup.exe|1048576|Apps|Downloads
```

## Apps you may not need

```
scan: complete
seconds: 4
limit: 45
format: 1
scanned: 2026-11-06
app|HP Support Assistant|1048576|2024-01-17
```

## Security: Secure Boot, TPM, BitLocker

```
Secure Boot: on
TPM:         present, ready: True, enabled: True
BitLocker:   On (FullyEncrypted) (C:)
Run as administrator: True
```

## Windows health and security

```
Drives: 1
Drive 1: SAMSUNG MZVLB512HBJQ-000H1 | type SSD | bus NVMe | size 477 GB | health Healthy | status OK | wear 3 | temperature 38 | read errors 0 | write errors 0 | power-on hours 6120
Defender real-time protection: on
Defender signature age days: 0
Defender last quick scan age days: 1
Defender last full scan age days: 30
Defender tamper protection: on
Other antivirus: none
BitLocker system drive: on
Firewall Domain: on
Firewall Private: on
Firewall Public: on
Remote Desktop: off
Remote Desktop network level authentication: required
SMBv1: off
Local administrator accounts: 1
Built-in Administrator account: disabled
Windows Update paused: no
UAC: on
UAC administrator prompt: asks for consent
```

## Security: Defender

```
AMServiceEnabled              : True
AntivirusEnabled              : True
RealTimeProtectionEnabled     : True
IsTamperProtected             : True
```

## Security: firewall

```
Name    Enabled
----    -------
Domain     True
Private    True
Public     True
```

## Backup status

```
File History: not set up
OneDrive signed in: yes
Folders saved to OneDrive (Windows Backup): Desktop, Documents, Pictures
OneDrive keeps these folders in sync, so it has no backup date. Other backup software cannot be detected by this scan.
```

## Backup freshness

```
format: 1
scanned: 2026-11-06
filehistory: not set up
filehistory-last: none
onedrive-folders: Desktop, Documents, Pictures
restorepoint-last: 2026-10-29
```

## Remote exposure

```
Remote Desktop (RDP): off
SMBv1: off
Automatic sign-in: off
```

## Windows Update

```
Newest installed update: 2026-10-29 (8 days ago)
Restart pending: no
```

## Startup programs

```
Count: 3
Name          Command                                        Location
----          -------                                        --------
OneDrive      "C:\Program Files\Microsoft OneDrive\OneDrive.exe" /background HKU\Run
AnyDesk       "C:\Program Files (x86)\AnyDesk\AnyDesk.exe" --tray HKLM\Run
SecurityHealth %windir%\system32\SecurityHealthSystray.exe   HKLM\Run
```

## Listening network ports

```
LocalAddress LocalPort Process
------------ --------- -------
0.0.0.0            135 svchost
0.0.0.0            445 System
0.0.0.0           7070 AnyDesk
```

## Hosts file (non-default lines)

```
127.0.0.1 activate.adobe.com
127.0.0.1 practivate.adobe.com
192.168.1.40 printer.devries-thuis.lan
```

## Crashes and unexpected shutdowns (last 30 days)

```
(none)
```

## Wi-Fi signal

```
format: 1
signal-percent: 82
band: 5 GHz
```

## Boot time

```
format: 1
boot-seconds: 41
boot-latest-seconds: 39
boot-latest: 2026-11-05
boot-samples: 6
```

## Recent app crashes

```
format: 1
scanned: 2026-11-06
window-days: 30
scan: complete
total: 4
app|chrome.exe|3
app|OUTLOOK.EXE|1
```

## Locale and time

```
2026-11-06 10:42:03 +01:00
W. Europe Standard Time
nl-NL
```

## Remote access tools

```
format: 1
scan: complete
tool|AnyDesk|installed,running
```

## Network settings

```
format: 1
hosts: complete
hosts-entries: 3
proxy: off
dns: complete
dns-server: 192.168.1.1
```

## Antivirus products

```
format: 1
status: complete
product|Windows Defender|on
```

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
