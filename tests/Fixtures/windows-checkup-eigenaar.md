# Windows checkup: DESKTOP-7K2M9QX

This report comes from one scan that ran once, only read information, and installed and changed nothing on this PC.

- Format: 1
- Script version: 7ff48491
- Update check: current
- OS: Windows
- Device type: desktop
- Generated: 2026-11-06 19:24
- Serial: CZC1EIGEN01
- Hardware UUID: 7D3A9C10-4E2B-11EF-9A1C-0242AC130003
- Model: HP Pavilion Gaming Desktop TG01-2xxx
- Chip/CPU: AMD Ryzen 5 5600G with Radeon Graphics (6 cores, 12 threads)
- Memory: 16 GB
- Windows: Windows 11 Pro 24H2 (build 26100.7171)
- Battery: none
- Free disk: 41%
- RustDesk ID: not found (RustDesk is not installed, or its settings file holds no readable ID)
- Secure Boot: on
- BitLocker: off
- Run as administrator: yes

## Things to look at

- BitLocker is OFF on C:.
- 1 enabled account(s) are not required to have a password (see Administrator accounts section).

## Windows version

```
Microsoft Windows 11 Pro 10.0.26100 (build 26100.7171)
Install date: 14-3-2025 09:12:40
Architecture: 64-bit
Edition: Professional
Last boot: 6-11-2026 17:58:03
Uptime can be inflated by Fast Startup, which keeps the kernel running across shutdowns.
```

## CPU and memory

```
Name                      : AMD Ryzen 5 5600G with Radeon Graphics
NumberOfCores             : 6
NumberOfLogicalProcessors : 12
MaxClockSpeed             : 3901
LoadPercentage            : 9

BankLabel    SizeGB Speed Manufacturer PartNumber      
---------    ------ ----- ------------ ----------      
P0 CHANNEL A      8  3200 Samsung      M471A1K43DB1-CWE
P0 CHANNEL B      8  3200 Samsung      M471A1K43DB1-CWE

Total: 16 GB, available: 38% of visible memory
```

## Storage (physical disks)

```
FriendlyName                   MediaType BusType SizeGB HealthStatus OperationalStatus
------------                   --------- ------- ------ ------------ -----------------
WDC PC SN530 SDBPNPZ-512G-1006 SSD       NVMe       477 Healthy      OK               
```

## Storage (volumes and free space)

```
DeviceID VolumeName FileSystem SizeGB FreeGB Free%
-------- ---------- ---------- ------ ------ -----
C:       Windows    NTFS          476    195    41
```

## Space you can get back

```
scan: complete
seconds: 21
limit: 60
format: 1
reclaim|recycle|524288|12
reclaim|temp|3355443|2210
reclaim|systemtemp|262144|40
reclaim|updates|1572864|18
reclaim|installers|838860|4
file|Battle.net-Setup.exe|10240|Apps|Downloads
file|Opname 2026-10-12.mp4|2306867|Videos|Videos
```

## Top processes by memory (grouped by name)

```
Name               MemoryMB Count
----               -------- -----
Discord                1260     8
chrome                 1180    21
Spotify                 640     7
Battle.net              410     5
com.docker.backend      380     1
steamwebhelper          350     9
MsMpEng                 240     1
explorer                190     1
OneDrive                120     1
svchost                 110    74
lsass                    24     1
System                    6     1
```

## Security: Secure Boot, TPM, BitLocker

```
Secure Boot: on
TPM:         present, ready: True, enabled: True
BitLocker:   Off (FullyDecrypted) (C:)
Run as administrator: True
```

## Windows health and security

```
Drives: 1
Drive 1: WDC PC SN530 SDBPNPZ-512G-1006 | type SSD | bus NVMe | size 477 GB | health Healthy | status OK | wear 2 | temperature 41 | read errors 0 | write errors 0 | power-on hours 3920
Defender real-time protection: on
Defender signature age days: 0
Defender last quick scan age days: 2
Defender last full scan age days: unknown
Defender tamper protection: on
Other antivirus: none
BitLocker system drive: off
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

## Security and remote access agents running

```
(none found)
```

## User accounts

```
Name               Enabled LastLogon          PasswordRequired
----               ------- ---------          ----------------
Administrator      False                      True            
DefaultAccount     False                      False           
Eigenaar           True    6-11-2026 18:02:41 False           
Gast               False                      False           
WDAGUtilityAccount False                      True            


Administrators group:

Name                          ObjectClass
----                          -----------
DESKTOP-7K2M9QX\Administrator User       
DESKTOP-7K2M9QX\Eigenaar      User       
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
restorepoint-last: 2026-10-30
```

## Remote exposure

```
Remote Desktop (RDP): off
SMBv1: off
Automatic sign-in: off
```

## Administrator accounts

```
Members of the Administrators group (not counting disabled accounts): 1
Enabled local accounts that do not need a password: 1
```

## Windows Update

```
Newest installed update: 2026-10-30 (7 days ago)
Restart pending: no
Pending file rename operations (often harmless): no

Pending updates:
(none)
```

## Startup programs

```
Count: 9
Name           Command                                                                         Location                                                                                      
----           -------                                                                         --------                                                                                      
OneDrive       "C:\Users\Eigenaar\AppData\Local\Microsoft\OneDrive\OneDrive.exe" /background   HKU\S-1-5-21-3623811015-3361044348-30300820-1001\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
Spotify        "C:\Users\Eigenaar\AppData\Roaming\Spotify\Spotify.exe" --autostart --minimized HKU\S-1-5-21-3623811015-3361044348-30300820-1001\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
Discord        "C:\Users\Eigenaar\AppData\Local\Discord\Update.exe" --processStart Discord.exe HKU\S-1-5-21-3623811015-3361044348-30300820-1001\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
Docker Desktop C:\Program Files\Docker\Docker\Docker Desktop.exe -Autostart                    HKU\S-1-5-21-3623811015-3361044348-30300820-1001\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
Battle.net     "C:\Program Files (x86)\Battle.net\Battle.net.exe" --autostarted                HKU\S-1-5-21-3623811015-3361044348-30300820-1001\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
LGHUB          "C:\Program Files\LGHUB\lghub.exe" --background                                 HKU\S-1-5-21-3623811015-3361044348-30300820-1001\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
Steam          "C:\Program Files (x86)\Steam\steam.exe" -silent                                HKU\S-1-5-21-3623811015-3361044348-30300820-1001\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
RtkAudUService "C:\Windows\System32\RtkAudUService64.exe" -background                          HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run                                            
SecurityHealth %windir%\system32\SecurityHealthSystray.exe                                     HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run                                            
```

## Listening network ports

```
LocalAddress LocalPort Process           
------------ --------- -------           
0.0.0.0            135 svchost           
::                 135 svchost           
0.0.0.0            445 System            
::                 445 System            
127.0.0.1         1120 Agent             
127.0.0.1         1122 Battle.net        
127.0.0.1         2375 com.docker.backend
0.0.0.0           5040 svchost           
127.0.0.1         6463 Discord           
127.0.0.1         7768 Spotify           
0.0.0.0          27036 steam             
0.0.0.0          49664 lsass             
0.0.0.0          49665 wininit           
0.0.0.0          57621 Spotify           
```

## Crashes and unexpected shutdowns (last 30 days)

```
(none)
```

## Wi-Fi signal

```
format: 1
status: not connected
```

## Boot time

```
format: 1
boot-seconds: 38
boot-latest-seconds: 35
boot-latest: 2026-11-06
boot-samples: 8
```

## Recent app crashes

```
format: 1
scanned: 2026-11-06
window-days: 30
scan: complete
total: 7
app|Spotify.exe|3
app|Discord.exe|2
app|Battle.net.exe|1
app|explorer.exe|1
```

## Locale and time

```
2026-11-06 19:25:12 +01:00
W. Europe Standard Time
nl-NL
```

## Remote access tools

```
format: 1
scan: complete
```

## Network settings

```
format: 1
hosts: complete
hosts-entries: 0
proxy: off
dns: complete
dns-server: 192.168.178.1
```

## Antivirus products

```
format: 1
status: complete
product|Windows Defender|on
```
