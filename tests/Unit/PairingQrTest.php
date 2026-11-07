<?php

declare(strict_types=1);

use App\Services\Scans\PairingQr;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

const PQ_URL = 'https://pc-checkup.thijssensoftware.nl/p/Ab3dEf6hIj9kLm2nOp5qRs8t';

/**
 * The modules the encoder chose, with the quiet zone around them, as rows of booleans.
 *
 * @return list<list<bool>>
 */
function pqExpected(string $url): array
{
    $matrix = Encoder::encode($url, ErrorCorrectionLevel::M())->getMatrix();
    $quiet = PairingQr::QUIET_ZONE;
    $size = $matrix->getWidth() + 2 * $quiet;
    $rows = [];

    for ($y = 0; $y < $size; $y++) {
        $row = [];
        for ($x = 0; $x < $size; $x++) {
            $inside = $x >= $quiet && $y >= $quiet && $x < $size - $quiet && $y < $size - $quiet;
            $row[] = $inside && $matrix->get($x - $quiet, $y - $quiet) === 1;
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Reads half block lines back into rows of modules: each character is two rows.
 *
 * @param  list<string>  $lines
 * @return list<list<bool>>
 */
function pqFromHalfBlocks(array $lines): array
{
    $rows = [];

    foreach ($lines as $line) {
        $top = [];
        $bottom = [];
        foreach (mb_str_split($line) as $char) {
            $top[] = in_array($char, ["\u{2588}", "\u{2580}"], true);
            $bottom[] = in_array($char, ["\u{2588}", "\u{2584}"], true);
        }
        $rows[] = $top;
        $rows[] = $bottom;
    }

    return $rows;
}

it('draws every module of the code, two rows per line, with a four module quiet zone', function () {
    $expected = pqExpected(PQ_URL);
    $lines = (new PairingQr)->lines(PQ_URL);
    $rows = pqFromHalfBlocks($lines);

    expect(count($lines))->toBe((int) ceil(count($expected) / 2))
        ->and(array_slice($rows, 0, count($expected)))->toBe($expected)
        ->and(array_slice($rows, count($expected)))->each->not->toContain(true);
});

it('keeps every line the same width and uses only spaces and the three half blocks', function () {
    $lines = (new PairingQr)->lines(PQ_URL);
    $width = mb_strlen($lines[0]);

    expect($width)->toBe(count(pqExpected(PQ_URL)))->toBeLessThanOrEqual(50);

    foreach ($lines as $line) {
        expect(mb_strlen($line))->toBe($width)
            ->and($line)->toMatch('/\A[ \x{2580}\x{2584}\x{2588}]+\z/u');
    }
});

it('frames the code in light modules, so a scanner finds its edge', function () {
    $lines = (new PairingQr)->lines(PQ_URL);

    expect(trim($lines[0]))->toBe('')
        ->and(trim($lines[1]))->toBe('')
        ->and(mb_substr($lines[2], 0, PairingQr::QUIET_ZONE))->toBe('    ')
        ->and(mb_substr($lines[2], -PairingQr::QUIET_ZONE))->toBe('    ');
});

it('draws the ASCII version as two characters per module, one line per row', function () {
    $expected = pqExpected(PQ_URL);
    $lines = (new PairingQr)->lines(PQ_URL, ascii: true);

    $rows = array_map(fn (string $line): array => array_map(fn (string $pair): bool => $pair === '##', str_split($line, 2)), $lines);

    expect($rows)->toBe($expected);

    foreach ($lines as $line) {
        expect($line)->toMatch('/\A(##|  )+\z/');
    }
});
