<?php

declare(strict_types=1);

use App\Services\Warranty\ReportSerial;
use App\Services\Warranty\WarrantyLink;

/**
 * @return array{maker: string, url: string, serial: string, serial_in_url: bool}|null
 */
function warrantyFor(string $model, string $serial = 'ABC12345', string $os = 'Windows'): ?array
{
    return (new WarrantyLink(new ReportSerial))->for("# R\n\n- OS: {$os}\n- Serial: {$serial}\n- Model: {$model}\n\n## Body\n");
}

it('picks the maker from the model', function (string $model, string $maker) {
    expect(warrantyFor($model)['maker'] ?? null)->toBe($maker);
})->with([
    ['Dell Inc. Latitude 5490', 'Dell'],
    ['Dell Latitude 5490', 'Dell'],
    ['HP ProDesk 400 G3 SFF', 'HP'],
    ['Hewlett-Packard HP EliteBook 840 G1', 'HP'],
    ['Lenovo ThinkPad T480 (20L5S0YT00)', 'Lenovo'],
    ['ASUSTeK COMPUTER INC. UX305CA', 'ASUS'],
    ['Acer Aspire A515-54', 'Acer'],
    ['Microsoft Corporation Surface Laptop 3', 'Microsoft Surface'],
    ['Micro-Star International Co., Ltd. MS-7C94', 'MSI'],
    ['MSI GF63 Thin 10SC', 'MSI'],
]);

it('treats every Mac as an Apple', function () {
    expect(warrantyFor('MacBook Air (MacBookAir7,2)', 'C02XXXXXXXXX', 'macOS'))->toBe([
        'maker' => 'Apple',
        'url' => 'https://checkcoverage.apple.com/',
        'serial' => 'C02XXXXXXXXX',
        'serial_in_url' => false,
    ]);
});

it('puts the service tag in the Dell address and nowhere else', function () {
    expect(warrantyFor('Dell Latitude 5490', 'ABC1234'))->toBe([
        'maker' => 'Dell',
        'url' => 'https://www.dell.com/support/home/en-us/product-support/servicetag/ABC1234/overview',
        'serial' => 'ABC1234',
        'serial_in_url' => true,
    ]);
});

it('gives no link for makers it cannot verify', function (string $model) {
    expect(warrantyFor($model))->toBeNull();
})->with(['Gigabyte Technology Co., Ltd. Z790 GAMING X AX', 'Contoso Voyager 14', 'Microsoft Corporation Virtual Machine', 'Dellwood Pro 5', 'Shp Thing 1']);

it('gives no link without a usable serial', function (string $serial) {
    expect(warrantyFor('Dell Latitude 5490', $serial))->toBeNull();
})->with(['Default string', '0', 'AB', '../../x?y=1']);

it('gives no link without a model', function () {
    expect((new WarrantyLink(new ReportSerial))->for("# R\n\n- Serial: ABC12345\n"))->toBeNull();
});

it('keeps every address in the map on https and flags the serial placeholder correctly', function () {
    foreach (WarrantyLink::MAKERS as $entry) {
        $url = str_replace('{serial}', 'ABC12345', $entry['url']);
        expect($url)->toStartWith('https://')->not->toContain('{')
            ->and($entry['serial_in_url'])->toBe(str_contains($entry['url'], '{serial}'));
    }
});
