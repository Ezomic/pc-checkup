# Mac checkup: Dutch-MacBook-Air

This report comes from one scan that ran once, only read information, and installed and changed nothing on this Mac.

- Format: 1
- OS: macOS
- Device type: laptop
- Generated: di  3 nov 2026 09:12:44 CET
- Serial: TESTSERIAL002
- Hardware UUID: BBBBBBBB-1111-2222-3333-444444444444
- Model: MacBook Air (MacBookAir7,2)
- Chip/CPU: Intel Core i5
- Memory: 8 GB
- macOS: 10.14.6 (18G9323)
- Battery: 391 cycles, condition Normal, max capacity n/a
- Free disk: 41%
- RustDesk ID: not found

## Things to look at

- macOS 10.14.6 is old and likely out of security support.
- Background indexing/backup process is using heavy CPU (Spotlight, Photos or Time Machine).

## Hardware overview

```
Hardware:

    Hardware Overview:

      Model Name: MacBook Air
      Model Identifier: MacBookAir7,2
      Processor Name: Dual-Core Intel Core i5
      Processor Speed: 1,8 GHz
      Number of Processors: 1
      Total Number of Cores: 2
      L2 Cache (per Core): 256 KB
      L3 Cache: 3 MB
      Memory: 8 GB
```

## macOS version

```
ProductName:	Mac OS X
ProductVersion:	10.14.6
BuildVersion:	18G9323
Darwin Dutch-MacBook-Air.local 18.7.0 Darwin Kernel Version 18.7.0
 9:12  up 2 days, 3:41, 2 users, load averages: 2,79 2,11 1,98
```

## Storage (boot volume)

```
   Device Node:               /dev/disk1s1
   Volume Name:               Macintosh HD
   Solid State:               Yes
   SMART Status:              Verified
   File System Personality:   APFS
```

## Model identifier and chip

```
MacBookAir7,2
Intel(R) Core(TM) i5-5350U CPU @ 1.80GHz
4
8 GB RAM
```

## Memory pressure

```
The system has 2147483648 (524288 pages with a page size of 4096).

Stats:
Pages free: 168432
Pages purgeable: 4021

System-wide memory free percentage: 79%
```

## Top processes by CPU

```
  PID  %CPU %MEM COMM
   66  69,2  0,1 mds
  217  25,5  0,4 mds_stores
  382   0,1  0,8 Spotify
```

## Top processes by memory

```
  PID  %CPU %MEM COMM
   66  54,2  0,1 mds
```

## Performance: load, swap, memory, throttling

```
vm.loadavg: { 1,41 1,41 1,89 }
vm.swapusage: total = 2048,00M  used = 328,25M  free = 1719,75M  (encrypted)
```

## Performance: Spotlight indexing

```
/:
	Indexing enabled. 
   66  69,2 mds
  217  25,5 mds_stores
```

## Space you can get back

```
scan: complete
seconds: 11
limit: 60
format: 1
reclaim|trash|1,2 GB|1.031
reclaim|caches|4,5 GB|1.234
reclaim|installers|880,5 MB|7
reclaim|backups|12,25 GB|2
snapshots|2
file|Vakantiefilm 2024.mov|7,5 GB|Videos|Movies
file|Belastingaangifte 2023.pdf|120,5 MB|Documents|Documenten
```

## Apps you may not need

```
scan: complete
seconds: 22
limit: 45
format: 1
scanned: 2026-11-05
app|Oude Video Editor|2,3 GB|2023-01-10
app|Archief Tool|650 MB|2022-06-01
more|2
```

## Backup freshness

```
format: 1
scanned: 2026-11-05
timemachine: not set up
timemachine-last: none
icloud-folders: none
```
