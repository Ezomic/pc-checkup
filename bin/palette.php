#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * palette.php - derive an app's colour tokens from one accent.
 *
 *   php bin/palette.php "#0E7490"
 *
 * Prints the `:root` and `.dark` blocks for resources/css/app.css. Paste them over the ones
 * already there; every shadcn variable keeps its name, so the whole component library picks
 * the new colours up without a single component changing.
 *
 * This is scaffolding, run once when an app gets its identity, so it lives here rather than
 * as an artisan command: nothing about it belongs in a deployed app's runtime.
 *
 * What is derived and what is fixed, and why:
 *
 *   - The accent itself drives --primary, --ring, --sidebar-primary, --sidebar-ring and
 *     --chart-1, so the colour turns up on every button, focus ring and first chart series.
 *   - Surfaces are a near-neutral ramp pulled toward the accent's hue. An app that is only
 *     white with a coloured button still reads as a shadcn demo; the tint is what makes it
 *     feel like its own product.
 *   - --card stays pure white. Load-bearing: apps with a severity ramp (flare, snag) draw
 *     92-95% lightness chips on cards, and tinting the card would force those to be retuned.
 *   - --destructive stays red in every app, including a red-branded one, so "delete" never
 *     borrows the brand colour and stops meaning danger.
 *   - --primary-foreground is whichever of white or near-black actually contrasts with the
 *     accent, not a guess.
 */

/*
 * Light and dark values that do not come from the accent. The four secondary chart
 * series stay the same in every app so a chart is readable across the estate, and
 * --destructive stays red so danger never changes meaning from one app to the next.
 */
const FIXED = [
    'destructive' => ['#c0392b', '#e05a4c'],
    'chart-2' => ['#1d9e75', '#3fbf95'],
    'chart-3' => ['#378add', '#5ba6ef'],
    'chart-4' => ['#ef9f27', '#f3b44f'],
    'chart-5' => ['#d4537e', '#e0749a'],
];

/**
 * @return array{0: int, 1: int, 2: int}
 */
function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * @return array{0: float, 1: float, 2: float} hue in degrees, saturation and lightness in 0..1
 */
function hexToHsl(string $hex): array
{
    [$r, $g, $b] = array_map(fn (int $c): float => $c / 255, hexToRgb($hex));

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $d = $max - $min;

    if ($d === 0.0) {
        return [0.0, 0.0, $l];
    }

    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

    $h = match ($max) {
        $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
        $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };

    return [$h * 60, $s, $l];
}

function hslToHex(float $h, float $s, float $l): string
{
    $h = fmod(fmod($h, 360) + 360, 360) / 360;
    $s = max(0.0, min(1.0, $s));
    $l = max(0.0, min(1.0, $l));

    if ($s === 0.0) {
        $channel = (int) round($l * 255);

        return sprintf('#%02x%02x%02x', $channel, $channel, $channel);
    }

    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;

    $channel = function (float $t) use ($p, $q): int {
        $t = fmod(fmod($t, 1.0) + 1.0, 1.0);

        $value = match (true) {
            $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
            $t < 1 / 2 => $q,
            $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
            default => $p,
        };

        return (int) round($value * 255);
    };

    return sprintf('#%02x%02x%02x', $channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3));
}

function relativeLuminance(string $hex): float
{
    $channels = array_map(function (int $c): float {
        $c /= 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, hexToRgb($hex));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrast(string $a, string $b): float
{
    $la = relativeLuminance($a);
    $lb = relativeLuminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Whichever of the two actually reads on the given background. */
function readableOn(string $background, string $light = '#ffffff', string $dark = '#14130f'): string
{
    return contrast($background, $light) >= contrast($background, $dark) ? $light : $dark;
}

/**
 * @return array<string, string>
 */
function lightTokens(string $accent, float $h, float $s): array
{
    $tint = fn (float $saturation, float $lightness): string => hslToHex($h, $s * $saturation, $lightness);

    $foreground = $tint(0.14, 0.14);
    $border = $tint(0.16, 0.895);
    $surfaceAccent = $tint(0.20, 0.935);

    return [
        'background' => $tint(0.22, 0.965),
        'foreground' => $foreground,
        'card' => '#ffffff',
        'card-foreground' => $foreground,
        'popover' => '#ffffff',
        'popover-foreground' => $foreground,
        'primary' => $accent,
        'primary-foreground' => readableOn($accent),
        'secondary' => $tint(0.20, 0.925),
        'secondary-foreground' => $foreground,
        'muted' => $tint(0.18, 0.955),
        'muted-foreground' => $tint(0.12, 0.44),
        'accent' => $surfaceAccent,
        'accent-foreground' => $foreground,
        'destructive' => FIXED['destructive'][0],
        'destructive-foreground' => '#ffffff',
        'border' => $border,
        'input' => $tint(0.18, 0.855),
        'ring' => $accent,
        'chart-1' => $accent,
        'chart-2' => FIXED['chart-2'][0],
        'chart-3' => FIXED['chart-3'][0],
        'chart-4' => FIXED['chart-4'][0],
        'chart-5' => FIXED['chart-5'][0],
        'radius' => '0.625rem',
        'sidebar-background' => $tint(0.26, 0.975),
        'sidebar-foreground' => $tint(0.10, 0.30),
        'sidebar-primary' => $accent,
        'sidebar-primary-foreground' => readableOn($accent),
        'sidebar-accent' => $surfaceAccent,
        'sidebar-accent-foreground' => $foreground,
        'sidebar-border' => $border,
        'sidebar-ring' => $accent,
        'sidebar' => $tint(0.26, 0.975),
    ];
}

/**
 * @return array<string, string>
 */
function darkTokens(float $h, float $s, float $l): array
{
    $tint = fn (float $saturation, float $lightness): string => hslToHex($h, $s * $saturation, $lightness);

    // The same accent at the same lightness disappears against a dark surface, so it is
    // lifted and kept saturated. Nothing below 60% reads as a colour down here.
    //
    // The floor only applies to an accent that is actually a colour. Forcing it on a grey
    // one would invent a hue out of nothing: hue is 0 when there is no saturation to
    // measure, so a grey accent came out red.
    $accent = hslToHex($h, $s < 0.12 ? $s : max($s, 0.55), max($l + 0.10, 0.60));

    $foreground = $tint(0.10, 0.92);
    $border = $tint(0.12, 0.205);
    $surfaceAccent = $tint(0.14, 0.205);
    $card = $tint(0.12, 0.125);

    return [
        'background' => $tint(0.14, 0.085),
        'foreground' => $foreground,
        'card' => $card,
        'card-foreground' => $foreground,
        'popover' => $card,
        'popover-foreground' => $foreground,
        'primary' => $accent,
        'primary-foreground' => readableOn($accent),
        'secondary' => $tint(0.12, 0.185),
        'secondary-foreground' => $foreground,
        'muted' => $tint(0.12, 0.165),
        'muted-foreground' => $tint(0.08, 0.63),
        'accent' => $surfaceAccent,
        'accent-foreground' => $foreground,
        'destructive' => FIXED['destructive'][1],
        'destructive-foreground' => '#ffffff',
        'border' => $border,
        'input' => $tint(0.12, 0.245),
        'ring' => $accent,
        'chart-1' => $accent,
        'chart-2' => FIXED['chart-2'][1],
        'chart-3' => FIXED['chart-3'][1],
        'chart-4' => FIXED['chart-4'][1],
        'chart-5' => FIXED['chart-5'][1],
        'radius' => '0.625rem',
        'sidebar-background' => $tint(0.14, 0.11),
        'sidebar-foreground' => $tint(0.08, 0.78),
        'sidebar-primary' => $accent,
        'sidebar-primary-foreground' => readableOn($accent),
        'sidebar-accent' => $surfaceAccent,
        'sidebar-accent-foreground' => $foreground,
        'sidebar-border' => $border,
        'sidebar-ring' => $accent,
        'sidebar' => $tint(0.14, 0.11),
    ];
}

/**
 * @param  array<string, string>  $tokens
 */
function block(string $selector, array $tokens): string
{
    $lines = [$selector.' {'];

    foreach ($tokens as $name => $value) {
        $lines[] = sprintf('    --%s: %s;', $name, $value);
    }

    $lines[] = '}';

    return implode("\n", $lines);
}

$accent = $argv[1] ?? '';

if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
    fwrite(STDERR, "usage: php bin/palette.php \"#RRGGBB\"\n");
    fwrite(STDERR, "The accent is the app's colour in Thijssensoftware ID's application catalog,\n");
    fwrite(STDERR, "which is where it is recorded once so the portal tile and the app agree.\n");

    exit(1);
}

$accent = strtolower($accent);
[$h, $s, $l] = hexToHsl($accent);

$light = lightTokens($accent, $h, $s);
$dark = darkTokens($h, $s, $l);

echo block(':root', $light)."\n\n";
echo block('.dark', $dark)."\n";

$lightContrast = contrast($accent, $light['primary-foreground']);
$darkContrast = contrast($dark['primary'], $dark['primary-foreground']);

fwrite(STDERR, sprintf(
    "\naccent %s is hue %d, saturation %d%%, lightness %d%%\n",
    $accent,
    (int) round($h),
    (int) round($s * 100),
    (int) round($l * 100),
));
fwrite(STDERR, sprintf(
    "primary against its foreground: %.1f:1 light, %.1f:1 dark\n",
    $lightContrast,
    $darkContrast,
));

if ($lightContrast < 4.5 || $darkContrast < 4.5) {
    fwrite(STDERR, "\nBelow 4.5:1, which fails WCAG AA for button labels. Darken the accent (light\n");
    fwrite(STDERR, "mode) rather than accepting it: a mid-tone accent is the usual cause, and the\n");
    fwrite(STDERR, "lighter version still works for the logo mark, which carries no text.\n");
}
