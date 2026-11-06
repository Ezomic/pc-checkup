<?php

declare(strict_types=1);

use App\Services\Warranty\ReportSerial;

function serialFrom(string $value): ?string
{
    return (new ReportSerial)->from("# Report\n\n- Serial: {$value}\n- Model: X\n\n## Body\n");
}

it('reads a serial from a Mac and a Windows header', function (string $value) {
    expect(serialFrom($value))->toBe($value);
})->with(['C02XXXXXXXXX', 'ABC1234', 'PF1ABCDE', 'abc-1234-xyz', str_repeat('A1', 15)]);

it('trims surrounding whitespace', function () {
    expect(serialFrom('  ABC1234  '))->toBe('ABC1234');
});

it('hides placeholder values', function (string $value) {
    expect(serialFrom($value))->toBeNull();
})->with([
    'To Be Filled By O.E.M.', 'Default string', 'System Serial Number', 'Chassis Serial Number', '0', '00000000', '1111111111',
    'Not Specified', 'None', 'Unknown', 'n/a', 'N/A', 'Not Available', '123456789', '0123456789', 'SerialNumber',
]);

it('hides values that are too short', function (string $value) {
    expect(serialFrom($value))->toBeNull();
})->with(['A', 'AB12', '----', '-A-B-']);

it('hides values that are too long instead of cutting them', function () {
    expect(serialFrom(str_repeat('A1', 16)))->toBeNull();
});

it('hides values with characters outside letters, digits and hyphens', function (string $value) {
    expect(serialFrom($value))->toBeNull();
})->with(['ABC 1234', 'ABC<script>alert(1)</script>', 'AB/../CD1234', 'ABC1234?x=1', 'Ünïcode12345', 'ABC_12345', "ABC1234\u{200B}", 'ABC12345"']);

it('hides a missing or empty serial', function () {
    expect((new ReportSerial)->from("# R\n\n- Model: X\n"))->toBeNull()
        ->and(serialFrom(''))->toBeNull();
});

it('ignores a serial line that sits inside a section', function () {
    expect((new ReportSerial)->from("# R\n\n## Hardware\n- Serial: ABC12345\n"))->toBeNull();
});
