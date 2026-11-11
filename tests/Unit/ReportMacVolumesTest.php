<?php

declare(strict_types=1);

use App\Services\Reports\ReportMacVolumes;

const MV_GIB = 1024 ** 3;

const MV_MIB = 1024 ** 2;

const MV_HEADER = 'Filesystem      Size   Used  Avail Capacity iused               ifree %iused  Mounted on';

const MV_MOJAVE = MV_HEADER."\n"
    ."/dev/disk1s1   113Gi   97Gi   13Gi    88%  900001 9223372036853800000    0%   /\n"
    ."devfs          184Ki  184Ki    0Bi   100%     600                   0  100%   /dev\n"
    ."/dev/disk1s4   113Gi  2.0Gi   13Gi    14%       2 9223372036854775800    0%   /private/var/vm\n"
    ."map -hosts       0Bi    0Bi    0Bi   100%       0                   0  100%   /net\n"
    ."map auto_home    0Bi    0Bi    0Bi   100%       0                   0  100%   /home\n"
    .'/dev/disk1s3   113Gi  487Mi   13Gi     4%      30 9223372036854775700    0%   /Volumes/Recovery';

const MV_SEQUOIA = "Filesystem        Size    Used   Avail Capacity iused ifree %iused  Mounted on\n"
    ."/dev/disk3s3s1   460Gi    17Gi   166Gi    10%    420k  1.7G    0%   /\n"
    ."devfs            202Ki   202Ki     0Bi   100%     690     0  100%   /dev\n"
    ."/dev/disk3s6     460Gi   1.0Gi   166Gi     1%       1  1.7G    0%   /System/Volumes/VM\n"
    ."/dev/disk3s4     460Gi    15Gi   166Gi     9%    1.8k  1.7G    0%   /System/Volumes/Preboot\n"
    ."/dev/disk3s2     460Gi   761Mi   166Gi     1%     280  1.7G    0%   /System/Volumes/Update\n"
    ."/dev/disk1s2     500Mi   6.0Mi   482Mi     2%       1  4.9M    0%   /System/Volumes/xarts\n"
    ."/dev/disk1s1     500Mi   6.0Mi   482Mi     2%      30  4.9M    0%   /System/Volumes/iSCPreboot\n"
    ."/dev/disk1s3     500Mi   1.3Mi   482Mi     1%      70  4.9M    0%   /System/Volumes/Hardware\n"
    ."/dev/disk3s1     460Gi   258Gi   166Gi    61%    6.1M  1.7G    0%   /System/Volumes/Data\n"
    ."map auto_home      0Bi     0Bi     0Bi   100%       0     0     -   /System/Volumes/Data/home\n"
    .'/dev/disk3s3     460Gi    17Gi   166Gi    10%    450k  1.7G    0%   /System/Volumes/Update/mnt1';

function mvBytes(?string $diskUsage): float
{
    return (new ReportMacVolumes)->bytes($diskUsage);
}

it('adds up swap and recovery on macOS 10.14, where everything else is the one walked volume', function () {
    expect(mvBytes(MV_MOJAVE))->toBe(2.0 * MV_GIB + 487 * MV_MIB);
});

it('counts swap, startup and update files on macOS 11 and later, but not the system volume mounted twice or another container', function () {
    expect(mvBytes(MV_SEQUOIA))->toBe(1.0 * MV_GIB + 15 * MV_GIB + 761 * MV_MIB);
});

it('reads the decimal commas of a Dutch Mac', function () {
    expect(mvBytes(str_replace(['2.0Gi', '487Mi'], ['2,5Gi', '0,5Gi'], MV_MOJAVE)))->toBe(3.0 * MV_GIB);
});

it('finds nothing without a usable line for the startup volume', function (?string $text) {
    expect(mvBytes($text))->toBe(0.0);
})->with([
    'no section' => null,
    'empty section' => '',
    'only the header' => MV_HEADER,
    'no line for the startup volume' => str_replace("0%   /\n", "0%   /Volumes/Old\n", MV_MOJAVE),
    'a startup volume that is not an APFS volume' => "/dev/disk2   931Gi   500Gi   431Gi    54%   1 2 0%   /\n/dev/disk2s4   931Gi  2.0Gi   431Gi    1%   1 2 0%   /private/var/vm",
    'a startup volume size that cannot be read' => str_replace('/dev/disk1s1   113Gi', '/dev/disk1s1   lots', MV_MOJAVE),
]);

it('leaves out volumes that are not part of the startup container', function (string $line) {
    expect(mvBytes(MV_MOJAVE."\n".$line))->toBe(2.0 * MV_GIB + 487 * MV_MIB);
})->with([
    'another disk of the same size' => '/dev/disk4s1   113Gi   50Gi   63Gi    44%   1 2 0%   /Volumes/Recovery',
    'another container size on the same disk number' => '/dev/disk1s7   200Gi   50Gi   63Gi    44%   1 2 0%   /System/Volumes/Preboot',
    'a volume of your own in the same container' => '/dev/disk1s5   113Gi   20Gi   13Gi    60%   1 2 0%   /Volumes/Work',
    'a volume with an accented name' => '/dev/disk1s5   113Gi   20Gi   13Gi    60%   1 2 0%   /Volumes/Récovery',
    'a volume mounted inside the walked data volume' => '/dev/disk1s5   113Gi   20Gi   13Gi    60%   1 2 0%   /System/Volumes/Data/Shared',
    'the data volume' => '/dev/disk1s5   113Gi   20Gi   13Gi    60%   1 2 0%   /System/Volumes/Data',
    'the swap volume listed twice' => '/dev/disk1s4   113Gi  2.0Gi   13Gi    14%   1 2 0%   /System/Volumes/VM',
    'a negative size' => '/dev/disk1s6   113Gi  -5Gi   13Gi    14%   1 2 0%   /System/Volumes/Update',
    'a used size that cannot be read' => '/dev/disk1s6   113Gi  n/a   13Gi    14%   1 2 0%   /System/Volumes/Update',
    'a line cut short' => '/dev/disk1s6   113Gi  9Gi',
]);

it('reads a line without the inode columns and with spaces in the mount point', function () {
    $text = "/dev/disk1s1   113Gi   97Gi   13Gi    88%   /\n/dev/disk1s4   113Gi  2.0Gi   13Gi    14%   /Volumes/VM\n/dev/disk1s6   113Gi  1.0Gi   13Gi    14%   /System/Volumes/Update/My Mount";

    expect(mvBytes($text))->toBe(3.0 * MV_GIB);
});

it('keeps the first startup volume line when df lists two', function () {
    expect(mvBytes(MV_MOJAVE."\n/dev/disk1s9   500Gi   97Gi   13Gi    88%   1 2 0%   /"))->toBe(2.0 * MV_GIB + 487 * MV_MIB);
});
