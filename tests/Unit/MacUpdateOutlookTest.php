<?php

declare(strict_types=1);

use App\Services\Reports\MacUpdateOutlook;
use App\Services\Reports\ReportSections;

const NOTHING_OFFERED = "No new software available.\nSoftware Update Tool\n\nFinding available software";

function outlookOf(?string $model, ?string $macos, ?string $updates): ?array
{
    $markdown = "# Mac checkup: Test-Mac\n\n- Format: 1\n"
        .($model === null ? '' : "- Model: {$model}\n")
        .($macos === null ? '' : "- macOS: {$macos}\n")
        .($updates === null ? '' : "\n## Software updates\n\n```\n{$updates}\n```\n");

    return (new MacUpdateOutlook)->read(ReportSections::parse($markdown));
}

it('names the newest macOS a Mac on an unsupported macOS can still run, and that it is unsupported too', function () {
    expect(outlookOf('MacBook Air (MacBookAir7,2)', '10.14.6 (18G9323)', NOTHING_OFFERED))
        ->toBe(['running' => 'macOS 10.14 Mojave', 'newest' => 'macOS 12 Monterey', 'newestSupported' => false]);
});

it('says nothing newer when the Mac already runs the newest macOS it can', function (string $model, string $macos, string $running) {
    expect(outlookOf($model, $macos, NOTHING_OFFERED))->toBe(['running' => $running, 'newest' => null, 'newestSupported' => false]);
})->with([
    'Monterey on a 2015 Air' => ['MacBook Air (MacBookAir7,2)', '12.7.6 (21H1320)', 'macOS 12 Monterey'],
    'Ventura on a 2017 Pro' => ['MacBook Pro (MacBookPro14,1)', '13.7.8 (22H730)', 'macOS 13 Ventura'],
    'High Sierra on a 2011 iMac' => ['iMac (iMac12,1)', '10.13.6 (17G14042)', 'macOS 10.13 High Sierra'],
]);

it('names a newer macOS that Apple still supports', function (string $model, string $newest) {
    expect(outlookOf($model, '12.7.6 (21H1320)', NOTHING_OFFERED))
        ->toBe(['running' => 'macOS 12 Monterey', 'newest' => $newest, 'newestSupported' => true]);
})->with([
    'the oldest supported line' => ['MacBook Air (MacBookAir8,1)', 'macOS 14 Sonoma'],
    'a newer line' => ['MacBook Pro (MacBookPro15,1)', 'macOS 15 Sequoia'],
]);

it('names a running macOS it has no name for by its number', function () {
    expect(outlookOf('MacBook Pro (MacBookPro5,1)', '10.10.5 (14F2511)', NOTHING_OFFERED))
        ->toBe(['running' => 'macOS 10.10', 'newest' => 'OS X 10.11 El Capitan', 'newestSupported' => false]);
});

it('reads the version from the macOS version section of an old report', function () {
    $markdown = "# Mac checkup: Test-Mac\n\n- Model: MacBook Air (MacBookAir7,2)\n\n## macOS version\n\n```\nProductName:\tMac OS X\nProductVersion:\t10.14.6\n```\n\n## Software updates\n\n```\n".NOTHING_OFFERED."\n```\n";

    expect((new MacUpdateOutlook)->read(ReportSections::parse($markdown)))->toBe(['running' => 'macOS 10.14 Mojave', 'newest' => 'macOS 12 Monterey', 'newestSupported' => false]);
});

it('has nothing to say when updates are waiting', function (string $updates) {
    expect(outlookOf('MacBook Air (MacBookAir7,2)', '10.14.6 (18G9323)', $updates))->toBeNull();
})->with([
    'Mojave style list' => "Software Update Tool\n\nFinding available software\nSoftware Update found the following new or updated software:\n   * Security Update 2021-005-10.14.6\n\tSecurity Update 2021-005 (10.14.6), 1626783K [recommended] [restart]",
    'label style list' => "Software Update Tool\n\nFinding available software\nSoftware Update found the following new or updated software:\n* Label: Safari27.2SequoiaAuto-27.2\n\tTitle: Safari, Version: 27.2, Size: 238722KiB, Recommended: YES,",
    'a list next to the no new software line' => "No new software available.\n* Label: Safari15.6.1-15.6.1",
]);

it('has nothing to say when the check did not finish', function (?string $updates) {
    expect(outlookOf('MacBook Air (MacBookAir7,2)', '10.14.6 (18G9323)', $updates))->toBeNull();
})->with([
    'no section' => null,
    'empty section' => '',
    'only the tool banner' => "Software Update Tool\n\nFinding available software",
    'network error' => "Software Update Tool\n\nFinding available software\nCan't connect to the Apple Software Update server.",
    'the words inside another line' => 'Error: No new software available could not be confirmed',
]);

it('has nothing to say while the macOS still gets security updates', function () {
    expect(outlookOf('MacBook Air (MacBookAir9,1)', '14.7.1 (23H222)', NOTHING_OFFERED))->toBeNull();
});

it('has nothing to say about a model it does not know', function (?string $model) {
    expect(outlookOf($model, '10.14.6 (18G9323)', NOTHING_OFFERED))->toBeNull();
})->with([
    'unknown identifier' => 'MacBook Air (MacBookAir99,9)',
    'no identifier' => 'MacBook Air',
    'no model line' => null,
    'unicode junk' => "Mac\u{202E} (\u{663},\u{664})",
]);

it('has nothing to say when the running macOS is unknown or newer than the model allows', function (?string $macos) {
    expect(outlookOf('MacBook Air (MacBookAir7,2)', $macos, NOTHING_OFFERED))->toBeNull();
})->with([
    'no version' => null,
    'not a version' => 'unknown',
    'patched to a newer macOS' => '13.6.1 (22G313)',
]);

it('says nothing about a Windows report', function () {
    $markdown = "# Windows checkup: PC\n\n- Windows: Windows 10 Pro 22H2\n\n## Software updates\n\n```\n".NOTHING_OFFERED."\n```\n";

    expect((new MacUpdateOutlook)->read(ReportSections::parse($markdown)))->toBeNull();
});
