# Mac checkup: Test-MacBook-Air

This report comes from one scan that ran once, only read information, and installed and changed nothing on this Mac.

- Format: 1
- OS: macOS
- Device type: laptop
- Generated: Tue Nov  3 09:12:44 CET 2026
- Serial: TESTSERIAL001
- Hardware UUID: AAAAAAAA-1111-2222-3333-444444444444
- Model: MacBook Air (MacBookAir7,2)
- Chip/CPU: Intel Core i5
- Memory: 8 GB
- macOS: 10.14.6 (18G9323)
- Battery: 391 cycles, condition Normal, max capacity n/a
- Free disk: 12%
- RustDesk ID: not found
- Warranty and coverage lookup: https://checkcoverage.apple.com (serial above)

## Things to look at

- Only about 12% of disk is free.
- macOS 10.14.6 is old and likely out of security support.

## macOS version

```
ProductName:	Mac OS X
ProductVersion:	10.14.6
BuildVersion:	18G9323
Darwin Test-MacBook-Air.local 18.7.0 Darwin Kernel Version 18.7.0
```

## Storage (boot volume)

```
   Device Node:               /dev/disk1s1
   Volume Name:               Macintosh HD
   Solid State:               Yes
   SMART Status:              Verified
   File System Personality:   APFS
```

## Disk usage

```
Filesystem      Size   Used  Avail Capacity iused               ifree %iused  Mounted on
/dev/disk1s1   113Gi   97Gi   14Gi    88%  842391 9223372036854933416    0%   /
devfs          190Ki  190Ki    0Bi   100%     657                    0  100%   /dev
/dev/disk1s4   113Gi  1.0Gi   14Gi     7%       1 9223372036854775806    0%   /private/var/vm
```

## Performance: biggest folders in home

```
38G	/Users/testuser/Library
21G	/Users/testuser/Movies
9.5G	/Users/testuser/Documents
2.1G	/Users/testuser/Library/Caches
780M	/Users/testuser/Downloads
12M	/Users/testuser/Desktop

(capped at about 15 seconds; folders not reached in time are not listed)
```

## Software update age

```
Newest installed macOS update: 2022-01-24 (1744 days ago)
```
