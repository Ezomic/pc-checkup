<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

const NM_MOJAVE_DISK = "Filesystem      Size   Used  Avail Capacity iused               ifree %iused  Mounted on\n"
    ."/dev/disk1s1   113Gi   97Gi   13Gi    88%  900001 9223372036853800000    0%   /\n"
    ."devfs          184Ki  184Ki    0Bi   100%     600                   0  100%   /dev\n"
    ."/dev/disk1s4   113Gi  2.0Gi   13Gi    14%       2 9223372036854775800    0%   /private/var/vm\n"
    ."map -hosts       0Bi    0Bi    0Bi   100%       0                   0  100%   /net\n"
    ."map auto_home    0Bi    0Bi    0Bi   100%       0                   0  100%   /home\n"
    .'/dev/disk1s3   113Gi  487Mi   13Gi     4%      30 9223372036854775700    0%   /Volumes/Recovery';

const NM_MOJAVE = "scan: complete\nseconds: 82\nlimit: 150\nformat: 2\n"
    ."root|Users|52499440\nroot|Applications|13333212\nroot|System|7239388\nroot|Library|4417652\nroot|private|3449500\nroot|usr|455276\nroots_total|81398076\n"
    ."home|52499428\nfolder|Library|8632156\nfolder|Applications|41207524\nhome|Applications|41207524\nhome|Library|8632156\n"
    ."missing|library/Mail\nmissing|library/Messages\nmissing|library/Safari";

const NM_SEQUOIA_DISK = "Filesystem        Size    Used   Avail Capacity iused ifree %iused  Mounted on\n"
    ."/dev/disk3s3s1   460Gi    17Gi   166Gi    10%    420k  1.7G    0%   /\n"
    ."/dev/disk3s6     460Gi   1.0Gi   166Gi     1%       1  1.7G    0%   /System/Volumes/VM\n"
    ."/dev/disk3s4     460Gi    15Gi   166Gi     9%    1.8k  1.7G    0%   /System/Volumes/Preboot\n"
    ."/dev/disk3s2     460Gi   761Mi   166Gi     1%     280  1.7G    0%   /System/Volumes/Update\n"
    ."/dev/disk1s2     500Mi   6.0Mi   482Mi     2%       1  4.9M    0%   /System/Volumes/xarts\n"
    ."/dev/disk3s1     460Gi   258Gi   166Gi    61%    6.1M  1.7G    0%   /System/Volumes/Data\n"
    .'/dev/disk3s3     460Gi    17Gi   166Gi    10%    450k  1.7G    0%   /System/Volumes/Update/mnt1';

const NM_SEQUOIA = "scan: complete\nseconds: 120\nlimit: 150\nformat: 2\nroot|Users|209715200\nroot|System|15728640\nroot|Applications|57671680\nroots_total|283115520";

const NM_NOT_MEASURED_NOTE = 'Not measured is used space that the folders above do not add up to, such as hidden system files, snapshots and anything the scan could not read.';

const NM_SUDO_NOTE = 'Run the scan again with sudo to measure the system files too.';

const NM_VOLUMES = ['label' => 'Space macOS keeps for itself', 'value' => '2.5 GB', 'detail' => '2% of used space: separate parts of the drive for swap, recovery and startup files', 'share' => 5];

const NM_UNREAD = 'system files that need admin rights, and folders macOS keeps private (Mail, Messages, Safari)';

function nmDoc(string $breakdown, ?string $disk = NM_MOJAVE_DISK, ?string $admin = 'no, sections that need sudo say so'): string
{
    $rights = $admin === null ? '' : "- Run as administrator: {$admin}\n";
    $usage = $disk === null ? '' : "\n## Disk usage\n\n```\n{$disk}\n```\n";

    return "# Mac checkup: Test Air\n\n- Format: 1\n- OS: macOS\n{$rights}{$usage}\n## Storage breakdown\n\n```\n{$breakdown}\n```\n";
}

/**
 * @return array{summary: string|null, bars: list<array{title: string, items: list<array{label: string, value: string, detail: string|null, share: int}>}>, notes: list<string>}
 */
function nmCard(string $markdown): array
{
    $card = collect((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'))->firstWhere('id', 'storage-breakdown');
    expect($card)->not->toBeNull();

    return $card;
}

/**
 * @param  array{bars: list<array{title: string, items: list<array{label: string, value: string, detail: string|null, share: int}>}>}  $card
 * @return list<array{label: string, value: string, detail: string|null, share: int}>
 */
function nmDrive(array $card): array
{
    expect($card['bars'][0]['title'])->toBe('Biggest folders on the drive');

    return $card['bars'][0]['items'];
}

describe('a Mac on macOS 10.14 scanned without admin rights', function () {
    it('splits what the walk did not reach into macOS volumes and files it could not read', function () {
        expect(nmDrive(nmCard(nmDoc(NM_MOJAVE))))->toBe([
            ['label' => 'Users', 'value' => '50 GB', 'detail' => '50% of used space', 'share' => 100],
            ['label' => 'Applications', 'value' => '13 GB', 'detail' => '13% of used space', 'share' => 25],
            ['label' => 'System', 'value' => '6.9 GB', 'detail' => '7% of used space', 'share' => 14],
            ['label' => 'Library', 'value' => '4.2 GB', 'detail' => '4% of used space', 'share' => 8],
            ['label' => 'private', 'value' => '3.3 GB', 'detail' => '3% of used space', 'share' => 7],
            ['label' => 'usr', 'value' => '445 MB', 'detail' => 'under 1% of used space', 'share' => 1],
            NM_VOLUMES,
            ['label' => 'Not measured', 'value' => '20 GB', 'detail' => '20% of used space: '.NM_UNREAD, 'share' => 40],
        ]);
    });

    it('says the same in the summary and tells the owner how to measure the system files', function () {
        $card = nmCard(nmDoc(NM_MOJAVE));

        expect($card['summary'])->toBe('Most of the space is used by Users (50 GB), Applications (13 GB) and System (6.9 GB). Your user folder uses 50 GB; the biggest part is Applications (39 GB). About 2.5 GB is space macOS keeps for itself, and 20 GB could not be read.')
            ->and(array_slice($card['notes'], 0, 3))->toBe([
                'Could not be read: Library: Mail, Library: Messages, Library: Safari.',
                NM_NOT_MEASURED_NOTE,
                NM_SUDO_NOTE,
            ]);
    });

    it('never suggests granting Full Disk Access', function () {
        expect(json_encode(nmCard(nmDoc(NM_MOJAVE)), JSON_THROW_ON_ERROR))->not->toContain('Full Disk Access')->not->toContain('Privacy');
    });

    it('reads the decimal commas of a Dutch Mac', function () {
        $dutch = str_replace(['113Gi', ' 13Gi', '2.0Gi', '487Mi'], ['112,5Gi', ' 12,5Gi', '2,0Gi', '0,5Gi'], NM_MOJAVE_DISK);
        $drive = nmDrive(nmCard(nmDoc(NM_MOJAVE, $dutch)));

        expect(array_slice($drive, -2))->toBe([
            ['label' => 'Space macOS keeps for itself', 'value' => '2.5 GB', 'detail' => '3% of used space: separate parts of the drive for swap, recovery and startup files', 'share' => 5],
            ['label' => 'Not measured', 'value' => '20 GB', 'detail' => '20% of used space: '.NM_UNREAD, 'share' => 40],
        ]);
    });
});

describe('the rights the scan ran with', function () {
    it('explains the rest by what those rights could not open', function (?string $admin, string $why, bool $sudo) {
        $card = nmCard(nmDoc(NM_MOJAVE, NM_MOJAVE_DISK, $admin));

        expect(nmDrive($card)[7]['detail'])->toBe("20% of used space: {$why}")
            ->and($card['summary'])->toEndWith('and 20 GB could not be read.')
            ->and(in_array(NM_SUDO_NOTE, $card['notes'], true))->toBe($sudo);
    })->with([
        'without admin rights' => ['no, sections that need sudo say so', NM_UNREAD, true],
        'with admin rights' => ['yes', 'folders macOS keeps private (Mail, Messages, Safari)', false],
        'an old report that does not say' => [null, 'files the scan could not open, such as system files and folders macOS keeps private (Mail, Messages, Safari)', false],
        'an answer that cannot be read' => ['maybe', 'files the scan could not open, such as system files and folders macOS keeps private (Mail, Messages, Safari)', false],
    ]);

    it('names only the private folders the scan said it could not read', function (string $missing, string $why) {
        $lines = str_replace("\nmissing|library/Mail\nmissing|library/Messages\nmissing|library/Safari", $missing, NM_MOJAVE);

        expect(nmDrive(nmCard(nmDoc($lines)))[7]['detail'])->toBe("20% of used space: {$why}");
    })->with([
        'none of them' => ['', 'system files that need admin rights, and folders macOS keeps private'],
        'one of them and another folder' => ["\nmissing|library/Messages\nmissing|home/Downloads\nmissing|library/Caches", 'system files that need admin rights, and folders macOS keeps private (Messages)'],
    ]);
});

describe('a scan that stopped at its time limit', function () {
    it('keeps the words it had for what was not measured, next to the macOS volumes', function (?string $admin) {
        $card = nmCard(nmDoc(str_replace('scan: complete', 'scan: partial (time limit of 150 seconds reached)', NM_MOJAVE), NM_MOJAVE_DISK, $admin));

        expect(array_slice(nmDrive($card), -2))->toBe([
            NM_VOLUMES,
            ['label' => 'Not measured', 'value' => '20 GB', 'detail' => '20% of used space', 'share' => 40],
        ])
            ->and($card['summary'])->toEndWith('About 2.5 GB is space macOS keeps for itself, and 20 GB was not measured.')
            ->and($card['notes'])->toContain(NM_NOT_MEASURED_NOTE)
            ->and($card['notes'])->not->toContain(NM_SUDO_NOTE);
    })->with(['no', 'yes', null]);
});

describe('macOS 11 and later', function () {
    it('counts swap, startup and update files once, and not the system volume that the walk already measured', function () {
        $card = nmCard(nmDoc(NM_SEQUOIA, NM_SEQUOIA_DISK, 'yes'));

        expect(array_slice(nmDrive($card), -2))->toBe([
            ['label' => 'Space macOS keeps for itself', 'value' => '17 GB', 'detail' => '6% of used space: separate parts of the drive for swap, recovery and startup files', 'share' => 8],
            ['label' => 'Not measured', 'value' => '7.3 GB', 'detail' => '2% of used space: folders macOS keeps private', 'share' => 4],
        ])
            ->and($card['summary'])->toEndWith('About 17 GB is space macOS keeps for itself, and 7.3 GB could not be read.')
            ->and($card['notes'])->not->toContain(NM_SUDO_NOTE);
    });
});

describe('macOS volumes bigger than what the walk did not reach', function () {
    it('shows the volumes and no negative or empty rest', function (string $total) {
        $card = nmCard(nmDoc(str_replace('roots_total|81398076', "roots_total|{$total}", NM_MOJAVE)));
        $drive = nmDrive($card);

        expect(end($drive))->toBe(NM_VOLUMES)
            ->and(array_column($drive, 'label'))->not->toContain('Not measured')
            ->and(implode(' ', array_column($drive, 'value')))->not->toContain('-')
            ->and($card['summary'])->toEndWith('About 2.5 GB is space macOS keeps for itself.')
            ->and($card['notes'])->not->toContain(NM_NOT_MEASURED_NOTE)
            ->and($card['notes'])->not->toContain(NM_SUDO_NOTE);
    })->with([
        'a gap smaller than the volumes' => ['103809024'],
        'no gap at all' => ['104857600'],
        'more measured than used' => ['209715200'],
    ]);
});

describe('what stays as it was', function () {
    it('has no macOS volumes and the old words without a Disk usage section', function () {
        $card = nmCard(nmDoc(NM_MOJAVE, null));

        expect(array_column(nmDrive($card), 'label'))->toBe(['Users', 'Applications', 'System', 'Library', 'private', 'usr'])
            ->and($card['summary'])->toBe('The biggest folders found are Users (50 GB), Applications (13 GB) and System (6.9 GB). Your user folder uses 50 GB; the biggest part is Applications (39 GB).')
            ->and($card['notes'])->not->toContain(NM_SUDO_NOTE);
    });

    it('has no macOS volumes when the startup volume line cannot be read', function () {
        $card = nmCard(nmDoc(NM_MOJAVE, str_replace('/dev/disk1s1   113Gi', '/dev/disk1s1   lots', NM_MOJAVE_DISK)));

        expect(array_column(nmDrive($card), 'label'))->toBe(['Users', 'Applications', 'System', 'Library', 'private', 'usr']);
    });

    it('has no macOS volumes in the first format, which has no total for the drive', function () {
        $card = nmCard(nmDoc("scan: complete\nroot|Users|52499440\nroot|System|7239388"));

        expect(array_column(nmDrive($card), 'label'))->toBe(['Users', 'System'])
            ->and($card['summary'])->toBe('Most of the space is used by Users (50 GB) and System (6.9 GB).');
    });

    it('keeps the Windows words for what was not measured', function () {
        $markdown = "# Windows checkup: Test PC\n\n- OS: Windows\n- Run as administrator: no\n\n## Storage (volumes and free space)\n\n```\nDeviceID VolumeName FileSystem SizeGB FreeGB Free%\nC:       OS         NTFS       100    20     20\n```\n\n"
            ."## Storage breakdown\n\n```\nscan: complete\nformat: 2\nroot|Windows|20971520\nroot|Users|31457280\nroots_total|52428800\nmissing|library/Mail\n```\n";
        $card = nmCard($markdown);

        expect(end($card['bars'][0]['items']))->toBe(['label' => 'Not measured', 'value' => '30 GB', 'detail' => '38% of used space', 'share' => 100])
            ->and($card['summary'])->toEndWith(' 30 GB was not measured.')
            ->and($card['notes'])->toContain(NM_NOT_MEASURED_NOTE)
            ->and($card['notes'])->not->toContain(NM_SUDO_NOTE);
    });
});
