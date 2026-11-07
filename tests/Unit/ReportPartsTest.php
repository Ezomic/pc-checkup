<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportParts;
use App\Services\Reports\ReportSections;

function prtSection(string $title, string $body): string
{
    return "\n## {$title}\n\n```\n{$body}\n```\n";
}

function prtWindowsModules(string ...$modules): string
{
    return prtSection('Memory modules', implode("\n", ['format: 1', 'scan: complete', ...$modules]));
}

function prtReadiness(string $slots = '4 total, 2 used', string $form = 'socketed', string $maximum = '128 GB', string $memory = '32 GB'): string
{
    return prtSection('Windows 11 readiness', "Firmware: UEFI\nMemory: {$memory}\nMemory slots: {$slots}\nMemory form: {$form}\nMemory maximum: {$maximum}");
}

function prtHealth(string ...$drives): string
{
    $lines = ['Drives: '.count($drives)];

    foreach (array_values($drives) as $index => $drive) {
        $lines[] = 'Drive '.($index + 1).": {$drive} | health Healthy | status OK | wear 2 | temperature 40 | read errors 0 | write errors 0 | power-on hours 100";
    }

    return prtSection('Windows health and security', implode("\n", $lines));
}

function prtWindows(string $body, string $memory = '32 GB'): string
{
    return "# Windows checkup: TEST\n\n- Format: 1\n- OS: Windows\n- Device type: desktop\n- Memory: {$memory}\n".$body;
}

function prtMac(string $body, string $memory = '16 GB', string $model = 'iMac (iMac19,1)'): string
{
    return "# Mac checkup: TEST\n\n- Format: 1\n- OS: macOS\n- Device type: desktop\n- Model: {$model}\n- Memory: {$memory}\n".prtSection('Memory and drives', $body);
}

function prtDesktop(): string
{
    return prtWindows(
        prtWindowsModules('module|DDR5-A2|16384 MB|DDR5|4800 MHz|DIMM', 'module|DDR5-B2|16384 MB|DDR5|4800 MHz|DIMM')
        .prtReadiness()
        .prtHealth('Samsung SSD 980 PRO 1TB | type SSD | bus NVMe | size 932 GB', 'WDC WD20EZRZ | type HDD | bus SATA | size 1863 GB', 'SanDisk Ultra | type Unspecified | bus USB | size 29 GB'),
    );
}

function prtIMac(): string
{
    return prtMac(implode("\n", [
        'format: 1',
        'memory: complete',
        'memory-upgradeable: yes',
        'module|DIMM1/BANK 0|8 GB|DDR4|2667 MHz|',
        'module|DIMM2/BANK 0|empty|||',
        'module|DIMM1/BANK 1|8 GB|DDR4|2667 MHz|',
        'module|DIMM2/BANK 1|empty|||',
        'drives: complete',
        'drive|SSD|SATA|121,33 GB',
        'drive|HDD|SATA|1000,2 GB',
    ]));
}

/**
 * @return array<string, string>
 */
function prtRows(string $markdown): array
{
    $card = (new ReportParts)->card(ReportSections::parse($markdown));

    expect($card)->not->toBeNull();

    return array_column($card['rows'] ?? [], 'value', 'label');
}

describe('the parts facts', function () {
    it('reads Windows memory modules, the slots and maximum of the readiness step and the drives of the health step', function () {
        $parts = (new ReportParts)->read(ReportSections::parse(prtDesktop()));

        expect($parts['modules'])->toBe([
            ['slot' => 'DDR5-A2', 'bytes' => 16.0 * 1024 ** 3, 'type' => 'DDR5', 'speed' => 4800, 'form' => 'DIMM'],
            ['slot' => 'DDR5-B2', 'bytes' => 16.0 * 1024 ** 3, 'type' => 'DDR5', 'speed' => 4800, 'form' => 'DIMM'],
        ])
            ->and($parts['slots'])->toBe(4)
            ->and($parts['free'])->toBe(2)
            ->and($parts['maximumGb'])->toBe(128)
            ->and($parts['installedGb'])->toBe(32.0)
            ->and($parts['fit'])->toBe('socketed')
            ->and($parts['drives'])->toBe([
                ['media' => 'SSD', 'bus' => 'NVMe', 'bytes' => 932.0 * 1024 ** 3],
                ['media' => 'HDD', 'bus' => 'SATA', 'bytes' => 1863.0 * 1024 ** 3],
            ]);
    });

    it('reads an older Windows report from the readiness step and the physical disks table alone', function () {
        $table = "FriendlyName         MediaType BusType SizeGB HealthStatus OperationalStatus\n------------         --------- ------- ------ ------------ -----------------\nST500LM000           HDD       SATA       466 Healthy      OK";
        $parts = (new ReportParts)->read(ReportSections::parse(prtWindows(prtReadiness('2 total, 1 used', 'socketed', '16 GB', '4 GB').prtSection('Storage (physical disks)', $table), '4 GB')));

        expect($parts['section'])->toBeFalse()
            ->and($parts['modules'])->toBe([])
            ->and($parts['slots'])->toBe(2)
            ->and($parts['free'])->toBe(1)
            ->and($parts['installedGb'])->toBe(4.0)
            ->and($parts['fit'])->toBe('socketed')
            ->and($parts['drives'])->toBe([['media' => 'HDD', 'bus' => 'SATA', 'bytes' => 466.0 * 1024 ** 3]]);
    });

    it('drops a drive size in the older table that no drive can have', function () {
        $table = "FriendlyName MediaType BusType SizeGB HealthStatus OperationalStatus\n------------ --------- ------- ------ ------------ -----------------\nDisk HDD SATA ".str_repeat('9', 400).' Healthy OK';
        $parts = (new ReportParts)->read(ReportSections::parse(prtWindows(prtSection('Storage (physical disks)', $table))));

        expect($parts['drives'])->toBe([['media' => 'HDD', 'bus' => 'SATA', 'bytes' => null]]);
    });

    it('treats LPDDR memory as soldered whatever the form factor says', function () {
        $parts = (new ReportParts)->read(ReportSections::parse(prtWindows(prtWindowsModules('module|ChannelA-DIMM0|8192 MB|LPDDR4|4267 MHz|SODIMM').prtReadiness('2 total, 1 used'))));

        expect($parts['fit'])->toBe('lpddr');
    });

    it('does not call memory socketed when a module has no known form factor', function () {
        $parts = (new ReportParts)->read(ReportSections::parse(prtWindows(prtWindowsModules('module|A|8192 MB|DDR4|2400 MHz|SODIMM', 'module|B|8192 MB|DDR4|2400 MHz|').prtReadiness('2 total, 2 used', 'soldered or unknown'))));

        expect($parts['fit'])->toBeNull();
    });

    it('reads a Mac with memory slots, an empty slot and a Fusion drive', function () {
        $parts = (new ReportParts)->read(ReportSections::parse(prtIMac()));

        expect($parts['section'])->toBeTrue()
            ->and(array_column($parts['modules'], 'slot'))->toBe(['DIMM1/BANK 0', 'DIMM2/BANK 0', 'DIMM1/BANK 1', 'DIMM2/BANK 1'])
            ->and($parts['modules'][1])->toBe(['slot' => 'DIMM2/BANK 0', 'bytes' => null, 'type' => null, 'speed' => null, 'form' => null])
            ->and($parts['slots'])->toBe(4)
            ->and($parts['free'])->toBe(2)
            ->and($parts['installedGb'])->toBe(16.0)
            ->and($parts['fit'])->toBe('socketed')
            ->and($parts['maximumGb'])->toBeNull()
            ->and($parts['drives'][0])->toBe(['media' => 'SSD', 'bus' => 'SATA', 'bytes' => 121.33 * 1024 ** 3]);
    });

    it('calls Mac memory soldered when the Mac says it cannot be upgraded, and unified on Apple silicon', function (string $lines, string $fit) {
        expect((new ReportParts)->read(ReportSections::parse(prtMac("format: 1\nmemory: complete\n{$lines}")))['fit'])->toBe($fit);
    })->with([
        'soldered Intel' => ["memory-upgradeable: no\nmodule|BANK 0/DIMM0|8 GB|LPDDR3|2133 MHz|", 'soldered'],
        'Apple silicon' => ["memory-unified: yes\nmemory-type: LPDDR5", 'unified'],
        'Apple silicon without a type' => ['memory-unified: yes', 'unified'],
    ]);

    it('falls back to the boot volume of an older Mac report for the drive', function (string $solid, string $media) {
        $markdown = "# Mac checkup: TEST\n\n- OS: macOS\n".prtSection('Storage (boot volume)', "   Solid State:               {$solid}\n   Protocol:                  SATA");

        expect((new ReportParts)->read(ReportSections::parse($markdown))['drives'])->toBe([['media' => $media, 'bus' => 'SATA', 'bytes' => null]]);
    })->with([['No', 'HDD'], ['Yes', 'SSD']]);

    it('reads nothing from a report that has none of these sections', function () {
        $parts = (new ReportParts)->read(ReportSections::parse("# Windows checkup: X\n\n- OS: Windows\n- Memory: n/a\n"));

        expect($parts['modules'])->toBe([])->and($parts['drives'])->toBe([])->and($parts['installedGb'])->toBeNull()
            ->and($parts['slots'])->toBeNull()->and($parts['free'])->toBeNull()->and($parts['fit'])->toBeNull();
    });
});

describe('the parts card', function () {
    it('shows a Windows desktop with its slots, modules and internal drives', function () {
        $card = (new ReportParts)->card(ReportSections::parse(prtDesktop()));

        expect($card['id'] ?? null)->toBe('parts')
            ->and($card['title'] ?? null)->toBe('Parts')
            ->and($card['rows'] ?? [])->toBe([
                ['label' => 'Memory', 'value' => '32 GB in 2 of 4 slots', 'tone' => 'neutral'],
                ['label' => 'Memory type', 'value' => 'DDR5 DIMM, 4800 MHz', 'tone' => 'neutral'],
                ['label' => 'Upgradeable', 'value' => 'Yes, 2 free slots', 'tone' => 'neutral'],
                ['label' => 'Most memory supported', 'value' => '128 GB', 'tone' => 'neutral'],
                ['label' => 'DDR5-A2', 'value' => '16 GB', 'tone' => 'neutral'],
                ['label' => 'DDR5-B2', 'value' => '16 GB', 'tone' => 'neutral'],
                ['label' => 'Drive 1', 'value' => 'NVMe SSD, 932 GB', 'tone' => 'neutral'],
                ['label' => 'Drive 2', 'value' => 'SATA hard drive (HDD), 1.8 TB', 'tone' => 'neutral'],
            ])
            ->and($card['notes'] ?? [])->toBe([
                'The most memory supported is what the firmware reports and is sometimes too low. The maker\'s specifications have the final word.',
                'Sizes, types and speeds only: serial and part numbers were not recorded.',
                'Only you see this: it is not on the pages you share with clients.',
            ]);
    });

    it('shows a Mac with memory slots, the empty ones included, and both halves of a Fusion drive', function () {
        expect(prtRows(prtIMac()))->toBe([
            'Memory' => '16 GB in 2 of 4 slots',
            'Memory type' => 'DDR4, 2667 MHz',
            'Upgradeable' => 'Yes, 2 free slots',
            'DIMM1/BANK 0' => '8 GB',
            'DIMM2/BANK 0' => 'Empty',
            'DIMM1/BANK 1' => '8 GB',
            'DIMM2/BANK 1' => 'Empty',
            'Drive 1' => 'SATA SSD, 121 GB',
            'Drive 2' => 'SATA hard drive (HDD), 1000 GB',
        ]);
    });

    it('says soldered memory cannot be upgraded and lists no slots for it', function () {
        $rows = prtRows(prtMac("format: 1\nmemory: complete\nmemory-upgradeable: no\nmodule|BANK 0/DIMM0|8 GB|LPDDR3|2133 MHz|\nmodule|BANK 1/DIMM0|8 GB|LPDDR3|2133 MHz|\ndrives: complete\ndrive|SSD|PCIe|500,28 GB", model: 'MacBook Pro (MacBookPro14,1)'));

        expect($rows)->toBe([
            'Memory' => '16 GB',
            'Memory type' => 'LPDDR3, 2133 MHz',
            'Upgradeable' => 'No, the memory is soldered to the board',
            'Drive 1' => 'PCIe SSD, 500 GB',
        ]);
    });

    it('says the memory of an Apple silicon Mac is part of the chip', function () {
        $rows = prtRows(prtMac("format: 1\nmemory: complete\nmemory-unified: yes\nmemory-type: LPDDR5\ndrives: complete\ndrive|SSD|NVMe|494,38 GB", '16 GB', 'MacBook Air (Mac14,2)'));

        expect($rows)->toBe([
            'Memory' => '16 GB',
            'Memory type' => 'LPDDR5',
            'Upgradeable' => 'No, the memory is part of the Apple chip',
            'Drive 1' => 'NVMe SSD, 494 GB',
        ]);
    });

    it('says LPDDR memory on Windows is soldered', function () {
        $rows = prtRows(prtWindows(prtWindowsModules('module|ChannelA-DIMM0|8192 MB|LPDDR4|4267 MHz|SODIMM', 'module|ChannelB-DIMM0|8192 MB|LPDDR4|4267 MHz|SODIMM').prtReadiness('2 total, 2 used', 'socketed', '16 GB', '16 GB'), '16 GB'));

        expect($rows['Upgradeable'])->toBe('No, LPDDR memory is soldered to the board')
            ->and($rows)->not->toHaveKey('ChannelA-DIMM0');
    });

    it('says how a full set of socketed slots can still grow', function () {
        $rows = prtRows(prtWindows(prtWindowsModules('module|DIMM1|2048 MB|DDR3|1600 MHz|SODIMM', 'module|DIMM2|2048 MB|DDR3|1600 MHz|SODIMM').prtReadiness('2 total, 2 used', 'socketed', '16 GB', '4 GB'), '4 GB'));

        expect($rows['Upgradeable'])->toBe('Only by swapping modules, all slots are in use')
            ->and($rows['Memory'])->toBe('4 GB in 2 of 2 slots');
    });

    it('counts free slots without claiming they take any module when the form factor is unknown', function (string $slots, string $value) {
        $rows = prtRows(prtWindows(prtWindowsModules('module|DIMM1|4096 MB|DDR4|2400 MHz|').prtReadiness($slots, 'soldered or unknown', '16 GB', '4 GB'), '4 GB'));

        expect($rows)->not->toHaveKey('Upgradeable')->and($rows['Free slots'])->toBe($value);
    })->with([['2 total, 1 used', '1'], ['1 total, 1 used', 'None']]);

    it('shows each speed when the modules differ', function () {
        $rows = prtRows(prtWindows(prtWindowsModules('module|A|8192 MB|DDR4|2400 MHz|SODIMM', 'module|B|4096 MB|DDR4|2667 MHz|SODIMM').prtReadiness('2 total, 2 used', 'socketed', '32 GB', '12 GB'), '12 GB'));

        expect($rows['Memory type'])->toBe('DDR4 SODIMM, 2400 to 2667 MHz')
            ->and($rows['A'])->toBe('8 GB, 2400 MHz')
            ->and($rows['B'])->toBe('4 GB, 2667 MHz');
    });

    it('labels eMMC, plain SSDs and drives of an unknown kind, and leaves USB drives out', function () {
        $rows = prtRows(prtWindows(prtWindowsModules('module|A|4096 MB|DDR3|1600 MHz|').prtHealth(
            'BJTD4R | type Unspecified | bus SD | size 58 GB',
            'Fast | type SSD | bus RAID | size 477 GB',
            'Odd | type could not check | bus SAS | size could not check',
            'Ghost | type Unspecified | bus could not check | size 10 GB',
            'Stick | type SSD | bus USB | size 58 GB',
        )));

        expect($rows['Drive 1'])->toBe('eMMC flash storage (usually soldered), 58 GB')
            ->and($rows['Drive 2'])->toBe('SSD, 477 GB')
            ->and($rows['Drive 3'])->toBe('SAS drive')
            ->and($rows['Drive 4'])->toBe('Drive, 10 GB')
            ->and($rows)->not->toHaveKey('Drive 5');
    });

    it('keeps every row label unique, so two slots with the same name both show', function () {
        $rows = (new ReportParts)->card(ReportSections::parse(prtWindows(prtWindowsModules('module|BANK 0|4096 MB|DDR4|2400 MHz|DIMM', 'module|BANK 0|4096 MB|DDR4|2400 MHz|DIMM', 'module|Memory|4096 MB|DDR4|2400 MHz|DIMM').prtReadiness('4 total, 3 used', 'socketed', '64 GB', '12 GB'))))['rows'] ?? [];
        $labels = array_column($rows, 'label');

        expect($labels)->toContain('BANK 0', 'BANK 0 (2)', 'Memory (2)')
            ->and(count($labels))->toBe(count(array_unique($labels)));
    });

    it('names a slot by its place when the scan gave no name', function () {
        $rows = prtRows(prtWindows(prtWindowsModules('module||4096 MB|DDR4|2400 MHz|DIMM', 'module|   |4096 MB|DDR4|2400 MHz|DIMM')));

        expect($rows)->toHaveKey('Slot 1')->toHaveKey('Slot 2');
    });

    it('flags a drive scan that stopped at its time limit and memory that could not be read', function () {
        $card = (new ReportParts)->card(ReportSections::parse(prtMac("format: 1\nmemory: could not check\ndrives: partial (time limit of 15 seconds reached)\ndrive|SSD|NVMe|251 GB")));

        expect($card['notes'] ?? [])->toContain('The memory could not be read.')
            ->toContain('The drive scan stopped at its time limit, so a drive may be missing.');
    });

    it('hides the card when the scan read nothing, or when the report predates the parts step', function (string $markdown) {
        expect((new ReportParts)->card(ReportSections::parse($markdown)))->toBeNull();
    })->with([
        'mac nothing read' => [prtMac("format: 1\nmemory: could not check\ndrives: could not check")],
        'mac empty section' => [prtMac('')],
        'windows could not check' => [prtWindows(prtSection('Memory modules', "format: 1\nscan: could not check"))],
        'windows no modules listed' => [prtWindows(prtWindowsModules())],
        'older windows report' => [prtWindows(prtReadiness().prtHealth('Samsung | type SSD | bus NVMe | size 932 GB'))],
        'older mac report' => ["# Mac checkup: X\n\n- OS: macOS\n- Memory: 8 GB\n".prtSection('Storage (boot volume)', '   Solid State: Yes')],
    ]);

    it('drops lines that do not have the exact shape, so a doctored report cannot add serials or markup', function () {
        $rows = prtRows(prtWindows(prtWindowsModules(
            'module|A|8192 MB|DDR4|2400 MHz|DIMM|S3R14L-NUMBER',
            'module|B|8192 MB|DDR4|2400 MHz',
            "module|C\u{0007}<b>x</b>\u{1F4BE}|8192 MB|<script>|99999999 MHz|BRICK",
            'module|D|-8 GB|DDR4|2400 MHz|DIMM',
            'module|E|99999999 GB|DDR4|2400 MHz|DIMM',
            'module|F|8,0 GB|DDR4|0 MHz|DIMM',
            'module|G|n/a|n/a|n/a|n/a',
        ), '8 GB'));
        $encoded = (string) json_encode($rows);

        expect($rows)->not->toHaveKey('A')->not->toHaveKey('B')->not->toHaveKey('D')->not->toHaveKey('E')->not->toHaveKey('G')
            ->and($rows["C<b>x</b>\u{1F4BE}"])->toBe('8 GB')
            ->and($rows['F'])->toBe('8 GB')
            ->and($rows['Memory type'])->toBe('DDR4 DIMM')
            ->and($encoded)->not->toContain('S3R14L')->not->toContain('script')->not->toContain('BRICK')->not->toContain('99999999');
    });

    it('reads a decimal comma in sizes and ignores a speed that is not a number of MHz', function () {
        $rows = prtRows(prtMac("format: 1\nmemory: complete\nmemory-upgradeable: yes\nmodule|DIMM1|4,0 GB|DDR3|1600 MHz|\nmodule|DIMM2|512 MB|DDR3|fast|\ndrives: complete\ndrive|HDD|SATA|500,11 GB", '4,5 GB'));

        expect($rows['DIMM1'])->toBe('4 GB')
            ->and($rows['DIMM2'])->toBe('512 MB')
            ->and($rows['Memory'])->toBe('4.5 GB in 2 of 2 slots')
            ->and($rows['Drive 1'])->toBe('SATA hard drive (HDD), 500 GB');
    });

    it('caps the number of modules and drives it lists', function () {
        $modules = array_map(fn (int $slot): string => "module|S{$slot}|4096 MB|DDR4|2400 MHz|DIMM", range(1, 40));
        $drives = array_map(fn (int $drive): string => "drive|SSD|SATA|{$drive} GB", range(1, 40));
        $rows = prtRows(prtMac(implode("\n", ['format: 1', 'memory: complete', 'memory-upgradeable: yes', ...$modules, 'drives: complete', ...$drives])));

        expect($rows)->toHaveKey('S'.ReportParts::MAX_MODULES)->not->toHaveKey('S'.(ReportParts::MAX_MODULES + 1))
            ->toHaveKey('Drive '.ReportParts::MAX_DRIVES)->not->toHaveKey('Drive '.(ReportParts::MAX_DRIVES + 1));
    });

    it('sits among the detail cards, before the verdict', function () {
        $ids = array_column((new ReportDetails(new ReportFacts))->cards(prtDesktop(), [], 'desktop'), 'id');

        expect($ids)->toContain('parts')
            ->and(array_search('parts', $ids, true))->toBeLessThan(array_search('verdict', $ids, true) ?: PHP_INT_MAX);
    });
});
