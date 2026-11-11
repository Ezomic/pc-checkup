<?php

declare(strict_types=1);

/**
 * @param  array<string, string>  $header
 * @param  list<string>  $flags
 */
function reportMarkdown(array $header = [], array $flags = ['Battery has 900 cycles (high).'], string $title = 'Mac checkup: Robbins-MacBook'): string
{
    $header = [
        'Format' => '1',
        'OS' => 'macOS',
        'Device type' => 'laptop',
        'Serial' => 'C02ABC123XYZ',
        'Hardware UUID' => '11111111-2222-3333-4444-555555555555',
        'Model' => 'MacBook Pro (MacBookPro18,3)',
        ...$header,
    ];

    $lines = ["# {$title}", ''];

    foreach ($header as $key => $value) {
        $lines[] = "- {$key}: {$value}";
    }

    $lines[] = '';
    $lines[] = '## Things to look at';
    $lines[] = '';

    foreach ($flags === [] ? ['Nothing flagged automatically.'] : $flags as $flag) {
        $lines[] = "- {$flag}";
    }

    $lines[] = '';
    $lines[] = '## Hardware overview';
    $lines[] = '';
    $lines[] = '```';
    $lines[] = '- Serial: SHOULD-NOT-BE-PARSED';
    $lines[] = '```';

    return implode("\n", $lines)."\n";
}
