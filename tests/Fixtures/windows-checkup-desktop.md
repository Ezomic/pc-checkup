# Windows checkup: TEST-DESKTOP

- Format: 1
- OS: Windows
- Device type: desktop
- Generated: 2026-11-01 17:55
- Serial:
- Hardware UUID: 4C4C4544-0053-5410-8058-B7C04F4B4E31
- Model: Gigabyte Technology Co., Ltd. Z790 GAMING X AX
- Chip/CPU: Intel(R) Core(TM) i7-14700K (20 cores, 28 threads)
- Memory: 32 GB
- Windows: Windows 11 Pro 26H2 (build 26300.9711)
- Battery: none
- Free disk: 17%
- Secure Boot: OFF
- BitLocker: OFF
- Run as administrator: no, some sections say unavailable

## Things to look at

- Secure Boot is OFF.
- BitLocker is OFF on C:.
- Security, management or remote access agents running (can slow a PC or give someone else access): rustdesk
- Windows updates are pending (see Windows Update section): 3.
- 10 unexpected shutdown(s) in the last 30 days (crash, power loss or forced power off).
- 1 disk error event(s) in the last 30 days (bad blocks or controller errors).

## Identity as reported by firmware (before placeholders are removed)

```
BIOS serial:        Default string
Product identifier: Default string
System UUID:        4C4C4544-0053-5410-8058-B7C04F4B4E31
Baseboard serial:   Default string

The header only uses values that look real. Blank means the firmware gave a placeholder.
```

## Storage (physical disks)

```
FriendlyName         MediaType BusType SizeGB HealthStatus OperationalStatus
------------         --------- ------- ------ ------------ -----------------
NVMe Samsung SSD 970 SSD       NVMe       932 Healthy      OK
```

## Storage (volumes and free space)

```
DeviceID VolumeName FileSystem SizeGB FreeGB Free%
-------- ---------- ---------- ------ ------ -----
C:                  NTFS          931    163    17
```

## Security: Secure Boot, TPM, BitLocker

```
Secure Boot: OFF
TPM:         present (Trusted Platform Module 2.0), details unavailable (needs admin)
BitLocker:   OFF (C:)
Run as administrator: False
```

## Activation

```
Licensed (Windows(R) Operating System, OEM_DM channel)

Edition: Professional
```

## Manual checklist (cannot be done by script)

Hardware
- [ ] Serial number matches the sticker on the case or the box.
- [ ] No BIOS or UEFI password is set (press F2, Del or F10 at startup).

Handover and cleanup
- [ ] Windows is activated with a genuine licence (Settings > System > Activation).
- [ ] BitLocker recovery key is known, or the disk is decrypted.
