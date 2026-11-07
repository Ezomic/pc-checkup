<?php

declare(strict_types=1);

use App\Models\PriceItem;
use App\Services\Quote\PriceItemMatcher;

/**
 * @return list<PriceItem>
 */
function pimItems(string ...$names): array
{
    return array_values(array_map(fn (string $name): PriceItem => (new PriceItem)->forceFill(['name' => $name, 'price_cents' => 1000, 'unit' => null]), $names));
}

function pimMatch(array $advice, string ...$names): ?string
{
    return (new PriceItemMatcher)->match($advice + ['memoryType' => null, 'moduleGb' => null, 'bus' => null, 'driveGb' => null, 'form' => null], pimItems(...$names))?->name;
}

describe('matching an SSD advice', function () {
    it('takes the first SSD item of the price list, in its own order', function () {
        expect(pimMatch(['part' => 'ssd'], 'Werkuur', 'Harde schijf 1 TB', 'SSD 500 GB incl. installatie', 'SSD 1 TB'))->toBe('SSD 500 GB incl. installatie');
    });

    it('knows a solid state drive by its full name', function () {
        expect(pimMatch(['part' => 'ssd'], 'Solid-state drive 480 GB'))->toBe('Solid-state drive 480 GB');
    });

    it('skips NVMe and M.2 drives when a SATA drive is swapped', function () {
        expect(pimMatch(['part' => 'ssd', 'bus' => 'SATA'], 'NVMe SSD 1 TB', 'M.2 SSD 500 GB', 'SSD 2,5 inch 500 GB'))->toBe('SSD 2,5 inch 500 GB')
            ->and(pimMatch(['part' => 'ssd', 'bus' => 'SATA'], 'NVMe SSD 1 TB', 'M2 SSD 500 GB'))->toBeNull();
    });

    it('skips an SSD that names a size clearly smaller than the drive it replaces', function (int $driveGb, string $expected) {
        expect(pimMatch(['part' => 'ssd', 'driveGb' => $driveGb], 'SSD 240GB', 'SSD 500 GB', 'SSD 1 TB', 'SSD plaatsen'))->toBe($expected);
    })->with([
        'a 500 GB drive Windows shows as 466 GB' => [466, 'SSD 500 GB'],
        'a 1 TB drive Windows shows as 932 GB' => [932, 'SSD 1 TB'],
        'a 1 TB drive a Mac shows as 1 TB' => [1024, 'SSD 1 TB'],
        'a 2 TB drive' => [1863, 'SSD plaatsen'],
        'a small drive' => [120, 'SSD 240GB'],
    ]);

    it('takes the first SSD whatever its size when the size of the drive is unknown', function () {
        expect(pimMatch(['part' => 'ssd'], 'SSD 240GB', 'SSD 1 TB'))->toBe('SSD 240GB');
    });

    it('never takes an external SSD or an enclosure for the drive inside the machine', function () {
        expect(pimMatch(['part' => 'ssd'], 'USB SSD behuizing', 'External SSD 1 TB', 'Externe SSD 500 GB', 'SSD enclosure', 'SSD 1 TB'))->toBe('SSD 1 TB')
            ->and(pimMatch(['part' => 'ssd'], 'Externe SSD 500 GB'))->toBeNull();
    });

    it('matches nothing that is not an SSD', function () {
        expect(pimMatch(['part' => 'ssd'], 'Werkuur', 'Harde schijf', 'HDD 2 TB', 'Accu', 'Classd amplifier'))->toBeNull();
    });
});

describe('matching a memory advice', function () {
    it('prefers the item of the same memory generation, then the one that names the advised size', function () {
        expect(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4], 'RAM 8 GB DDR3', 'Geheugen 4 GB', 'DDR4 geheugen', 'RAM 4GB DDR4'))->toBe('RAM 4GB DDR4')
            ->and(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4], 'Geheugen 4 GB', 'DDR4 geheugen'))->toBe('DDR4 geheugen');
    });

    it('never takes a memory item that names another size than the advised module', function () {
        expect(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4], 'RAM 8 GB DDR4', 'RAM 16GB DDR4', 'Geheugen uitbreiden'))->toBe('Geheugen uitbreiden')
            ->and(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4], 'RAM 8 GB DDR4', 'RAM 14 GB DDR4'))->toBeNull();
    });

    it('never takes desktop memory for a laptop slot or the other way round', function () {
        expect(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4, 'form' => 'SODIMM'], 'RAM 4 GB DDR4 DIMM', 'RAM 4 GB DDR4 SO-DIMM'))->toBe('RAM 4 GB DDR4 SO-DIMM')
            ->and(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4, 'form' => 'DIMM'], 'RAM 4 GB DDR4 SODIMM', 'Geheugen 4 GB'))->toBe('Geheugen 4 GB')
            ->and(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4, 'form' => 'DIMM'], 'RAM 4 GB DDR4 SODIMM'))->toBeNull()
            ->and(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4], 'RAM 4 GB DDR4 SODIMM'))->toBe('RAM 4 GB DDR4 SODIMM');
    });

    it('takes a memory item that names no generation when none names the right one', function () {
        expect(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 8], 'RAM 8 GB DDR3', 'Werkgeheugen uitbreiden'))->toBe('Werkgeheugen uitbreiden');
    });

    it('never takes memory of another generation, LPDDR included', function () {
        expect(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 8], 'RAM 8 GB DDR3', 'LPDDR4 8 GB', 'DDR5 SODIMM 8 GB'))->toBeNull();
    });

    it('takes only an item that names no generation when the generation is unknown', function () {
        expect(pimMatch(['part' => 'memory', 'moduleGb' => 4], 'RAM 4 GB DDR3', 'Memory upgrade'))->toBe('Memory upgrade')
            ->and(pimMatch(['part' => 'memory', 'moduleGb' => 4], 'RAM 4 GB DDR3'))->toBeNull();
    });

    it('does not mistake a memory card, a USB stick or another word for memory', function () {
        expect(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4], 'Geheugenkaart 64 GB', 'SD memory card', 'USB-stick geheugen', 'Programma installeren', 'Framework'))->toBeNull();
    });

    it('reads unicode and odd spacing in names', function () {
        expect(pimMatch(['part' => 'memory', 'memoryType' => 'DDR3', 'moduleGb' => 4], "Géheugen\u{00A0}uitbreiding", 'RAM  4  GB  DDR3 ✓'))->toBe('RAM  4  GB  DDR3 ✓');
    });
});

it('matches nothing in an empty price list', function () {
    expect(pimMatch(['part' => 'ssd']))->toBeNull()
        ->and(pimMatch(['part' => 'memory', 'memoryType' => 'DDR4', 'moduleGb' => 4]))->toBeNull();
});
