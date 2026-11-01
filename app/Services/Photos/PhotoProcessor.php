<?php

declare(strict_types=1);

namespace App\Services\Photos;

use GdImage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PhotoProcessor
{
    private const MAX_EDGE = 1600;

    // GD holds about 4 bytes per pixel, and rotating keeps a second copy, so this stays
    // decodable inside PHP's default 128M memory limit. A 12 MP phone photo fits.
    private const MAX_PIXELS = 16_000_000;

    private const QUALITY = 82;

    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Decodes, applies the EXIF orientation, scales down and re-encodes as JPEG. Re-encoding
     * is what drops the EXIF block (GPS position, device serial) along with everything else.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public function process(string $contents): array
    {
        $info = $this->quietly(fn (): array|false => getimagesizefromstring($contents));

        // The size check comes before decoding: a few hundred bytes can declare an image
        // that takes gigabytes of memory to decode.
        if ($info === false || ! in_array($info[2], self::ACCEPTED, true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw $this->invalid();
        }

        $source = $this->quietly(fn (): GdImage|false => imagecreatefromstring($contents));

        if ($source === false) {
            throw $this->invalid();
        }

        $source = $this->orient($source, $info[2] === IMAGETYPE_JPEG ? $contents : null);

        return $this->scale($source);
    }

    private function orient(GdImage $image, ?string $jpeg): GdImage
    {
        $angle = match ($jpeg === null ? 1 : $this->orientation($jpeg)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function orientation(string $jpeg): int
    {
        $stream = fopen('php://temp', 'r+') ?: throw new RuntimeException('Cannot open a temporary stream.');

        fwrite($stream, $jpeg);
        rewind($stream);

        $exif = $this->quietly(fn (): array|false => exif_read_data($stream));
        fclose($stream);

        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($value) ? $value : 1;
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    private function scale(GdImage $source): array
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, self::MAX_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        // JPEG has no alpha, and a transparent PNG would otherwise turn black.
        imagefill($canvas, 0, 0, 0xFFFFFF);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);

        return ['bytes' => (string) ob_get_clean(), 'width' => $newWidth, 'height' => $newHeight];
    }

    /**
     * The image functions report bad input as warnings, which the framework turns into
     * exceptions, so a corrupt upload would be a 500 instead of a form error.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function quietly(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['photo' => 'That file is not a photo this app can read. Use a JPEG, PNG or WebP image.']);
    }
}
