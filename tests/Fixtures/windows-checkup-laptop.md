# Windows checkup: TEST-LAPTOP

This report comes from one scan that ran once, only read information, and installed and changed nothing on this PC.

- Format: 1
- OS: Windows
- Device type: laptop
- Generated: 2026-11-03 09:12
- Serial: TESTSERIAL002
- Hardware UUID: BBBBBBBB-1111-2222-3333-444444444444
- Model: Contoso Voyager 14
- Chip/CPU: Intel(R) Core(TM) i5-8250U (4 cores, 8 threads)
- Memory: 8 GB
- Windows: Windows 10 Pro 22H2 (build 19045.5000)
- Battery: 312 cycles, 85% of design capacity
- Free disk: 22%
- Run as administrator: yes

## Things to look at

- Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.

## Storage (physical disks)

```
FriendlyName         MediaType BusType SizeGB HealthStatus OperationalStatus
------------         --------- ------- ------ ------------ -----------------
Contoso NVMe 256GB   SSD       NVMe       238 Healthy      OK
```

## Storage (volumes and free space)

```
DeviceID VolumeName FileSystem SizeGB FreeGB Free%
-------- ---------- ---------- ------ ------ -----
C:       OS         NTFS          237     52    22
D:       Data Files NTFS          500    400    80
```

## Windows Update

```
Newest installed update: 2026-10-20 (14 days ago)
Restart pending: no
```

## Security: Secure Boot, TPM, BitLocker

```
Secure Boot: on
TPM:         present, ready: True, enabled: True
BitLocker:   On (FullyEncrypted) (C:)
Run as administrator: True
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
Public    False
```

## Backup status

```
File History: not set up
OneDrive signed in: yes
Folders saved to OneDrive (Windows Backup): Desktop, Documents
OneDrive keeps these folders in sync, so it has no backup date. Other backup software cannot be detected by this scan.
```

## Remote exposure

```
Remote Desktop (RDP): ENABLED (network level authentication required: yes)
SMBv1: off
Automatic sign-in: off
```

## Startup programs

```
Count: 3
Name     Command           Location
----     -------           --------
OneDrive C:\x\OneDrive.exe HKU\Run
```

## Crashes and unexpected shutdowns (last 30 days)

```
TimeCreated           Id ProviderName
-----------           -- ------------
11/1/2026 3:12:00 AM  41 Microsoft-Windows-Kernel-Power
11/1/2026 3:12:10 AM  1001 Microsoft-Windows-WER-SystemErrorReporting
10/20/2026 8:00:00 PM 41 Microsoft-Windows-Kernel-Power
```

## Space you can get back

```
scan: partial (time limit of 60 seconds reached)
seconds: 60
limit: 60
format: 1
reclaim|recycle|2411724|87
reclaim|temp|1572864|412
reclaim|systemtemp|307200|36
reclaim|updates|2097152|14
reclaim|installers|1363148|5
reclaim|windowsold|15728640|6
file|disk-image.vhdx|20971520|Other|Virtual Machines
file|Game Recording.mp4|4194304|Videos|Videos
file|Setup.exe|1572864|Apps|Downloads
```

## Apps you may not need

```
scan: complete
seconds: 3
limit: 45
format: 1
scanned: 2026-11-06
app|Old CAD Program|4194304|2024-02-10
app|Photo Suite 2018|2097152|unknown
```

## Backup freshness

```
format: 1
scanned: 2026-11-06
filehistory: set up
filehistory-last: 2026-08-14
onedrive-folders: none
restorepoint-last: 2026-09-30
```
