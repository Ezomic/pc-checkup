<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportDriveHealth;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportWindowsHealth;
use App\Services\Reports\ReportWindowsSecurity;

function whFixture(string $name): string
{
    return (string) file_get_contents(__DIR__."/../Fixtures/windows-health-{$name}.md");
}

function whReplace(string $markdown, string $before, string $after): string
{
    $changed = str_replace($before, $after, $markdown);
    expect($changed)->not->toBe($markdown);

    return $changed;
}

function whDoc(string $body, string $device = 'laptop'): string
{
    return "# Windows checkup: Test\n\n- Format: 1\n- Device type: {$device}\n\n## Windows health and security\n\n```\n{$body}\n```\n";
}

/**
 * @return array<string, array{value: string, tone: string}>
 */
function whRows(string $markdown, string $card): array
{
    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop') as $found) {
        if ($found['id'] === $card) {
            return array_combine(array_column($found['rows'], 'label'), array_map(fn (array $row): array => ['value' => $row['value'], 'tone' => $row['tone']], $found['rows']));
        }
    }

    return [];
}

/**
 * @return list<string>
 */
function whDriveRows(string $markdown): array
{
    return array_column((new ReportDriveHealth)->rows(ReportSections::parse($markdown)), 'value', 'label');
}

function whDrive(string $line): array
{
    return whDriveRows(whDoc("Drives: 1\nDrive 1: {$line}"));
}

/**
 * @return list<string>
 */
function whReasons(string $markdown): array
{
    $sections = ReportSections::parse($markdown);

    return array_column([...(new ReportDriveHealth)->reasons($sections), ...(new ReportWindowsSecurity)->reasons($sections)], 'text');
}

describe('the drive rows of the storage card', function () {
    it('says how a healthy drive is doing', function () {
        expect(whRows(whFixture('laptop-healthy'), 'storage')['Samsung SSD 970'])->toBe(['value' => 'Healthy, 4% worn, 41 C', 'tone' => 'good']);
    });

    it('marks a drive that is not healthy, worn out and failing reads as bad, with advice', function () {
        $rows = whRows(whFixture('laptop-unhealthy'), 'storage');

        expect($rows['Samsung SSD 970'])->toBe(['value' => 'Warning, 91% worn, 63 C, 14 read errors. Back up your files and replace this drive soon.', 'tone' => 'bad']);
    });

    it('keeps a USB drive in the list but never calls it bad', function () {
        $rows = whRows(whFixture('laptop-unhealthy'), 'storage');

        expect($rows['Backup disk (USB)'])->toBe(['value' => 'Unhealthy', 'tone' => 'neutral']);
    });

    it('reads the health word whatever its case and judges only the words it knows', function () {
        expect(whDrive('A | health healthy'))->toBe(['A' => 'Healthy'])
            ->and(whDrive('A | health WARNING'))->toBe(['A' => 'Warning. Back up your files and replace this drive soon.'])
            ->and(whDrive('A | health Degraded'))->toBe(['A' => 'Degraded'])
            ->and(whReasons(whDoc('Drive 1: A | health Degraded')))->toBe([])
            ->and(array_column((new ReportDriveHealth)->rows(ReportSections::parse(whDoc('Drive 1: A | health Degraded'))), 'tone'))->toBe(['neutral']);
    });

    it('reads a decimal comma and rounds the wear', function () {
        expect(whRows(whFixture('dutch-laptop'), 'storage')['Samsung SSD 970 EVO Plus'])->toBe(['value' => 'Healthy, 5% worn, 41 C', 'tone' => 'good']);
    });

    it('treats wear as bad only above the threshold', function (string $wear, string $tone) {
        expect(whRows(whDoc("Drive 1: A | health Healthy | wear {$wear}"), 'storage')['A']['tone'])->toBe($tone);
    })->with([
        'at 80' => ['80', 'good'],
        'just above, with a point' => ['80.5', 'bad'],
        'just above, with a comma' => ['80,5', 'bad'],
        'full' => ['100', 'bad'],
        'low' => ['0', 'good'],
    ]);

    it('ignores numbers it cannot trust', function (string $field) {
        expect(whDrive("A | health Healthy | {$field}"))->toBe(['A' => 'Healthy']);
    })->with([
        'wear text' => ['wear abc'],
        'wear negative' => ['wear -5'],
        'wear over a hundred' => ['wear 101'],
        'wear huge' => ['wear 99999999999'],
        'wear exponent' => ['wear 1e2'],
        'wear nan' => ['wear NaN'],
        'wear empty' => ['wear '],
        'wear unreadable' => ['wear could not check'],
        'temperature text' => ['temperature hot'],
        'temperature huge' => ['temperature 500'],
        'temperature negative' => ['temperature -3'],
        'read errors negative' => ['read errors -3'],
        'read errors fraction' => ['read errors 2.5'],
        'read errors text' => ['read errors lots'],
        'read errors messy' => ['read errors 1,2,3'],
        'read errors overflow' => ['read errors 99999999999999999999'],
        'read errors above the cap' => ['read errors 2000000000'],
        'temperature zero' => ['temperature 0'],
    ]);

    it('counts read errors with a thousands mark', function () {
        expect(whDrive('A | health Healthy | read errors 1.250'))->toBe(['A' => 'Healthy, 1250 read errors. Back up your files and replace this drive soon.'])
            ->and(whDrive('A | health Healthy | read errors 1'))->toBe(['A' => 'Healthy, 1 read error. Back up your files and replace this drive soon.'])
            ->and(whDrive('A | health Healthy | read errors 0'))->toBe(['A' => 'Healthy']);
    });

    it('hides a drive that has nothing readable', function () {
        expect(whDrive('A | health could not check | wear could not check | temperature could not check | read errors could not check'))->toBe([])
            ->and(whDrive('A | health Unknown'))->toBe([])
            ->and(whDrive('A'))->toBe([]);
    });

    it('rounds the temperature and tells apart drives with the same name', function () {
        $rows = whDriveRows(whDoc("Drive 1: Same | health Healthy | temperature 40.6\nDrive 2: Same | health Healthy\nDrive 3: Same | health Warning"));

        expect($rows)->toBe([
            'Same' => 'Healthy, 41 C',
            'Same (2)' => 'Healthy',
            'Same (3)' => 'Warning. Back up your files and replace this drive soon.',
        ]);
    });

    it('cleans names and limits them', function () {
        $rows = whDriveRows(whDoc("Drive 1: Evil\x07\tDisk <b>x</b> | health Healthy\nDrive 2:  | health Healthy\nDrive 3: ".str_repeat('Ünï', 40).' | health Healthy'));

        expect(array_keys($rows))->toBe(['Evil Disk <b>x</b>', 'Drive 2', str_repeat('Ünï', 16).'Ün'])
            ->and(mb_strlen(array_keys($rows)[2]))->toBe(50);
    });

    it('shows no more than eight drives', function () {
        $lines = implode("\n", array_map(fn (int $number): string => "Drive {$number}: D{$number} | health Healthy", range(1, 12)));

        expect(array_keys(whDriveRows(whDoc($lines))))->toBe(['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8']);
    });

    it('shows nothing for an old report, an unreadable section or a Mac', function () {
        expect(whDriveRows((string) file_get_contents(__DIR__.'/../Fixtures/windows-checkup-laptop.md')))->toBe([])
            ->and(whDriveRows(whDoc('Drives: could not check')))->toBe([])
            ->and(whDriveRows(macReportFixture()))->toBe([])
            ->and(whDriveRows(whDoc('')))->toBe([]);
    });

    it('lists the drives after the drive type, once per drive', function () {
        $labels = array_keys(whRows(whFixture('laptop-healthy'), 'storage'));

        expect($labels)->toBe(['Free', 'Used', 'Health', 'Drive type', 'Samsung SSD 970']);
    });
});

describe('the security rows', function () {
    it('shows a healthy laptop as all good', function () {
        expect(whRows(whFixture('laptop-healthy'), 'security'))->toBe([
            'Antivirus' => ['value' => 'Microsoft Defender is on', 'tone' => 'good'],
            'Virus definitions' => ['value' => '1 day old', 'tone' => 'good'],
            'Last virus scan' => ['value' => 'Quick scan 2 days ago, full scan 9 days ago', 'tone' => 'neutral'],
            'Tamper protection' => ['value' => 'On', 'tone' => 'good'],
            'Disk encryption' => ['value' => 'On', 'tone' => 'good'],
            'Windows Firewall' => ['value' => 'On', 'tone' => 'good'],
            'Remote Desktop' => ['value' => 'Off', 'tone' => 'good'],
            'SMBv1' => ['value' => 'Off', 'tone' => 'good'],
            'Accounts with administrator rights' => ['value' => '1 account', 'tone' => 'neutral'],
            'Built-in Administrator account' => ['value' => 'Off', 'tone' => 'good'],
            'Windows Update paused' => ['value' => 'No', 'tone' => 'good'],
            'User Account Control' => ['value' => 'On', 'tone' => 'good'],
        ]);
    });

    it('leaves BitLocker off on a desktop as a plain fact, but not on a laptop', function () {
        $desktop = whRows(whFixture('desktop-healthy'), 'security')['Disk encryption'];
        $laptop = whRows(whReplace(whFixture('laptop-healthy'), 'BitLocker system drive: on', 'BitLocker system drive: off'), 'security')['Disk encryption'];
        $unknown = whRows(whReplace(whFixture('desktop-healthy'), '- Device type: desktop', '- Device type: '), 'security')['Disk encryption'];

        expect($desktop)->toBe(['value' => 'Off', 'tone' => 'neutral'])
            ->and($laptop['tone'])->toBe('bad')
            ->and($laptop['value'])->toContain('lost')
            ->and($unknown)->toBe(['value' => 'Off', 'tone' => 'neutral']);
    });

    it('goes red for everything wrong on an unhealthy laptop', function () {
        $rows = whRows(whFixture('laptop-unhealthy'), 'security');

        expect($rows['Antivirus'])->toBe(['value' => 'Off. Turn on virus protection in Windows Security.', 'tone' => 'bad'])
            ->and($rows['Virus definitions'])->toBe(['value' => '40 days old. Open Windows Security and check for updates.', 'tone' => 'bad'])
            ->and($rows['Last virus scan'])->toBe(['value' => 'Quick scan never, full scan never', 'tone' => 'neutral'])
            ->and($rows['Tamper protection']['tone'])->toBe('neutral')
            ->and($rows['Windows Firewall'])->toBe(['value' => 'Off for Private and Public. Turn it on in Windows Security.', 'tone' => 'bad'])
            ->and($rows['Remote Desktop']['tone'])->toBe('bad')
            ->and($rows['SMBv1']['tone'])->toBe('bad')
            ->and($rows['Accounts with administrator rights'])->toBe(['value' => '4 accounts', 'tone' => 'neutral'])
            ->and($rows['Built-in Administrator account']['tone'])->toBe('bad')
            ->and($rows['Windows Update paused']['value'])->toStartWith('Until 1 December 2026.')
            ->and($rows['User Account Control']['tone'])->toBe('bad');
    });

    it('lets another security program manage a switched off firewall', function () {
        $rows = whRows(whDoc("Other antivirus: Norton\nFirewall Domain: on\nFirewall Private: on\nFirewall Public: off"), 'security');

        expect($rows['Windows Firewall'])->toBe(['value' => 'Off for Public. Another security program is installed and may manage the firewall.', 'tone' => 'neutral'])
            ->and(whReasons(whDoc("Other antivirus: Norton\nFirewall Public: off")))->toBe([]);
    });

    it('does not worry about old signatures or a stopped Defender when another antivirus is registered', function () {
        $rows = whRows(whFixture('dutch-laptop'), 'security');

        expect($rows['Antivirus'])->toBe(['value' => 'ESET Security, Norton 360 (Microsoft Defender is off)', 'tone' => 'neutral'])
            ->and($rows['Virus definitions'])->toBe(['value' => '30 days old', 'tone' => 'neutral'])
            ->and($rows)->not->toHaveKey('Tamper protection')
            ->and($rows['Remote Desktop']['tone'])->toBe('neutral')
            ->and($rows['Remote Desktop']['value'])->toContain('network level authentication');
    });

    it('draws the signature line at fourteen days', function (string $days, string $tone, string $value) {
        $rows = whRows(whDoc("Other antivirus: none\nDefender signature age days: {$days}"), 'security');

        expect($rows['Virus definitions'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'today' => ['0', 'good', 'Updated today'],
        'fourteen' => ['14', 'good', '14 days old'],
        'fifteen' => ['15', 'bad', '15 days old. Open Windows Security and check for updates.'],
    ]);

    it('ignores signature ages it cannot trust', function (string $days) {
        expect(whRows(whDoc("Defender signature age days: {$days}\nUAC: on"), 'security'))->not->toHaveKey('Virus definitions');
    })->with(['negative' => '-1', 'text' => 'old', 'huge' => '99999999', 'fraction' => '1.5.5', 'unreadable' => 'could not check']);

    it('does not call old signatures bad when it is unknown whether another antivirus exists', function () {
        $rows = whRows(whDoc("Other antivirus: could not check\nDefender signature age days: 90"), 'security');

        expect($rows['Virus definitions']['tone'])->toBe('neutral');
    });

    it('reads every Defender and antivirus combination', function (string $lines, ?string $value, ?string $tone) {
        $row = whRows(whDoc($lines), 'security')['Antivirus'] ?? null;

        expect($row['value'] ?? null)->toBe($value)->and($row['tone'] ?? null)->toBe($tone);
    })->with([
        'on' => ["Defender real-time protection: on\nOther antivirus: none", 'Microsoft Defender is on', 'good'],
        'off with nothing else' => ["Defender real-time protection: off\nOther antivirus: none", 'Off. Turn on virus protection in Windows Security.', 'bad'],
        'off with another' => ["Defender real-time protection: off\nOther antivirus: Norton", 'Norton (Microsoft Defender is off)', 'neutral'],
        'off and the list is unknown' => ["Defender real-time protection: off\nOther antivirus: could not check", 'Microsoft Defender is off', 'neutral'],
        'off and the list is missing' => ['Defender real-time protection: off', 'Microsoft Defender is off', 'neutral'],
        'unknown with another' => ["Defender real-time protection: could not check\nOther antivirus: Norton", 'Norton', 'neutral'],
        'unknown with none' => ["Defender real-time protection: could not check\nOther antivirus: none\nUAC: on", null, null],
        'garbage' => ["Defender real-time protection: maybe\nOther antivirus: none\nUAC: on", null, null],
    ]);

    it('reads the firewall profiles', function (string $lines, ?string $value, ?string $tone) {
        $row = whRows(whDoc($lines."\nUAC: on"), 'security')['Windows Firewall'] ?? null;

        expect($row['value'] ?? null)->toBe($value)->and($row['tone'] ?? null)->toBe($tone);
    })->with([
        'all on' => ["Firewall Domain: on\nFirewall Private: on\nFirewall Public: on", 'On', 'good'],
        'one off' => ["Firewall Domain: on\nFirewall Private: on\nFirewall Public: off", 'Off for Public. Turn it on in Windows Security.', 'bad'],
        'all off' => ["Firewall Domain: off\nFirewall Private: off\nFirewall Public: off", 'Off for Domain, Private and Public. Turn it on in Windows Security.', 'bad'],
        'one off, others unknown' => ["Firewall Domain: could not check\nFirewall Private: off\nFirewall Public: could not check", 'Off for Private. Turn it on in Windows Security.', 'bad'],
        'some unknown' => ["Firewall Domain: on\nFirewall Private: could not check\nFirewall Public: on", 'Not fully checked', 'neutral'],
        'all unknown' => ["Firewall Domain: could not check\nFirewall Private: could not check\nFirewall Public: could not check", null, null],
        'missing' => ['', null, null],
    ]);

    it('reads Remote Desktop and network level authentication', function (string $lines, ?string $tone, ?string $contains) {
        $row = whRows(whDoc($lines."\nUAC: on"), 'security')['Remote Desktop'] ?? null;

        expect($row['tone'] ?? null)->toBe($tone)->and($row['value'] ?? '')->toContain($contains ?? '');
    })->with([
        'off' => ['Remote Desktop: off', 'good', 'Off'],
        'on without the check' => ["Remote Desktop: on\nRemote Desktop network level authentication: not required", 'bad', 'does not check who is signing in'],
        'on with the check' => ["Remote Desktop: on\nRemote Desktop network level authentication: required", 'neutral', 'network level authentication'],
        'on, check unknown' => ["Remote Desktop: on\nRemote Desktop network level authentication: could not check", 'neutral', 'Turn it off'],
        'on, check missing' => ['Remote Desktop: on', 'neutral', 'Turn it off'],
        'unknown' => ["Remote Desktop: could not check\nRemote Desktop network level authentication: not required", null, null],
    ]);

    it('reads User Account Control', function (string $lines, ?string $tone) {
        expect(whRows(whDoc($lines), 'security')['User Account Control']['tone'] ?? null)->toBe($tone);
    })->with([
        'on' => ["UAC: on\nUAC administrator prompt: asks", 'good'],
        'on, prompt unknown' => ["UAC: on\nUAC administrator prompt: could not check", 'good'],
        'off' => ["UAC: off\nUAC administrator prompt: asks", 'bad'],
        'on but silent' => ["UAC: on\nUAC administrator prompt: never asks", 'bad'],
        'unknown' => ["UAC: could not check\nUAC administrator prompt: never asks\nSMBv1: off", null],
    ]);

    it('reads the administrator accounts and a paused Windows Update', function () {
        $rows = whRows(whDoc("Local administrator accounts: 12\nBuilt-in Administrator account: enabled\nWindows Update paused: yes, until 2026-02-30"), 'security');

        expect($rows['Accounts with administrator rights'])->toBe(['value' => '12 accounts', 'tone' => 'neutral'])
            ->and($rows['Built-in Administrator account']['tone'])->toBe('bad')
            ->and($rows)->not->toHaveKey('Windows Update paused');

        $unknown = whRows(whDoc("Local administrator accounts: many\nBuilt-in Administrator account: maybe\nWindows Update paused: sometimes\nSMBv1: off"), 'security');

        expect(array_keys($unknown))->toBe(['SMBv1']);
    });

    it('limits a long antivirus list and cleans it', function () {
        $rows = whRows(whDoc("Defender real-time protection: off\nOther antivirus: Evil\x07\t".str_repeat('x', 200)), 'security');

        expect(mb_strlen($rows['Antivirus']['value']))->toBeLessThan(120)->and($rows['Antivirus']['value'])->not->toContain("\x07");
    });

    it('replaces the older Windows rows when the section is there, and keeps them when it is not', function () {
        $old = (string) file_get_contents(__DIR__.'/../Fixtures/windows-checkup-laptop.md');
        $mixed = $old."\n## Windows health and security\n\n```\nDefender real-time protection: could not check\nUAC: on\n```\n";
        $oldLabels = array_keys(whRows($old, 'security'));

        $mixedLabels = array_keys(whRows($mixed, 'security'));

        expect($oldLabels)->toContain('Disk encryption', 'Windows Firewall', 'Defender real-time protection')
            ->and($mixedLabels)->toBe(array_values(array_merge(array_slice($oldLabels, 0, (int) array_search('Disk encryption', $oldLabels, true)), ['User Account Control'], array_slice($oldLabels, (int) array_search('Disk encryption', $oldLabels, true)))));
    });

    it('falls back on the older rows when nothing in the section could be read', function () {
        $old = (string) file_get_contents(__DIR__.'/../Fixtures/windows-checkup-laptop.md');
        $mixed = $old."\n## Windows health and security\n\n```\nDrives: could not check\nDefender real-time protection: could not check\nBitLocker system drive: could not check\n```\n";

        expect(whRows($mixed, 'security'))->toBe(whRows($old, 'security'));
    });

    it('leaves a Mac untouched', function () {
        $labels = array_keys(whRows(macReportFixture(), 'security'));

        expect($labels)->not->toContain('Antivirus', 'Virus definitions', 'User Account Control');
    });
});

describe('what needs attention', function () {
    it('has nothing to say about a healthy laptop, a desktop without encryption or an old report', function () {
        expect(whReasons(whFixture('laptop-healthy')))->toBe([])
            ->and(whReasons(whFixture('desktop-healthy')))->toBe([])
            ->and(whReasons(whFixture('dutch-laptop')))->toBe([])
            ->and(whReasons((string) file_get_contents(__DIR__.'/../Fixtures/windows-checkup-laptop.md')))->toBe([])
            ->and(whReasons(macReportFixture()))->toBe([]);
    });

    it('lists the severe results of an unhealthy laptop once each', function () {
        expect(whReasons(whFixture('laptop-unhealthy')))->toBe([
            'A drive reports that it is not healthy',
            'A drive is more than 80% worn out',
            'A drive has reported read errors',
            'No antivirus protection is switched on',
            'Virus definitions are more than 14 days old',
            'BitLocker is off on a laptop',
            'Windows Firewall is switched off',
            'SMBv1, an outdated file sharing protocol, is switched on',
            'User Account Control is switched off',
        ]);
    });

    it('rates each reason as urgent or soon', function () {
        $sections = ReportSections::parse(whFixture('laptop-unhealthy'));
        $levels = array_column([...(new ReportDriveHealth)->reasons($sections), ...(new ReportWindowsSecurity)->reasons($sections)], 'level', 'text');

        expect($levels['A drive reports that it is not healthy'])->toBe('urgent')
            ->and($levels['A drive is more than 80% worn out'])->toBe('urgent')
            ->and($levels['A drive has reported read errors'])->toBe('soon')
            ->and($levels['No antivirus protection is switched on'])->toBe('urgent')
            ->and($levels['SMBv1, an outdated file sharing protocol, is switched on'])->toBe('urgent')
            ->and($levels['Windows Firewall is switched off'])->toBe('soon');
    });

    it('does not count a USB drive', function () {
        expect(whReasons(whDoc("Drive 1: Stick | type HDD | bus USB | health Unhealthy | wear 99 | read errors 50\nDrive 2: Other | bus usb | health Warning")))->toBe([]);
    });

    it('says that a silent User Account Control is a problem too', function () {
        expect(whReasons(whDoc("UAC: on\nUAC administrator prompt: never asks")))->toBe(['User Account Control never asks before changes']);
    });

    it('reads the section whatever the case of its keys', function () {
        expect(whReasons(whDoc('uac: OFF')))->toBe(['User Account Control is switched off']);
    });
});

describe('the facts reader', function () {
    it('keeps the first value of a key and ignores lines that are not facts', function () {
        $facts = (new ReportWindowsHealth)->read(ReportSections::parse(whDoc("UAC: on\nUAC: off\nnonsense without a colon\n: empty key\nSMBv1:\n".str_repeat('k', 90).': long key')));

        expect((new ReportWindowsHealth)->value($facts, 'UAC'))->toBe('on')
            ->and((new ReportWindowsHealth)->value($facts, 'SMBv1'))->toBeNull()
            ->and(array_keys($facts['values']))->toBe(['uac']);
    });

    it('returns nothing without the section', function () {
        expect((new ReportWindowsHealth)->read(ReportSections::parse(macReportFixture())))->toBeNull();
    });
});
