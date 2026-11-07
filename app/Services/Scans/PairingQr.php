<?php

declare(strict_types=1);

namespace App\Services\Scans;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;

/**
 * Draws a QR code as lines of text for a terminal, so the scripts need no QR encoder of their own.
 * Dark modules are drawn and light ones are spaces: the scripts print the lines dark on light.
 */
final class PairingQr
{
    public const QUIET_ZONE = 4;

    private const FULL = "\u{2588}";

    private const UPPER = "\u{2580}";

    private const LOWER = "\u{2584}";

    /**
     * Half blocks fit two rows of modules in one line. The ASCII version uses two characters per
     * module and a line per row, for a console that cannot show the blocks.
     *
     * @return list<string>
     */
    public function lines(string $text, bool $ascii = false): array
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        $size = $matrix->getWidth() + 2 * self::QUIET_ZONE;
        $lines = [];

        for ($y = 0; $y < $size; $y += $ascii ? 1 : 2) {
            $line = '';

            for ($x = 0; $x < $size; $x++) {
                $line .= $ascii
                    ? ($this->dark($matrix, $x, $y) ? '##' : '  ')
                    : $this->halfBlock($this->dark($matrix, $x, $y), $this->dark($matrix, $x, $y + 1));
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function halfBlock(bool $top, bool $bottom): string
    {
        return match (true) {
            $top && $bottom => self::FULL,
            $top => self::UPPER,
            $bottom => self::LOWER,
            default => ' ',
        };
    }

    private function dark(ByteMatrix $matrix, int $x, int $y): bool
    {
        $x -= self::QUIET_ZONE;
        $y -= self::QUIET_ZONE;

        return $x >= 0 && $y >= 0 && $x < $matrix->getWidth() && $y < $matrix->getHeight() && $matrix->get($x, $y) === 1;
    }
}
