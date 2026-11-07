<?php

declare(strict_types=1);

namespace App\Services\Scans;

use Thijssensoftware\FlareClient\Payload\Sanitiser;

/**
 * Flare scrubs query strings but reports the path as it is, and a pairing id travels in the path
 * of the approve page and of the script's poll. Those segments are filtered here, and so is the
 * route parameter that carries the same id.
 */
final class PairingUrlSanitiser extends Sanitiser
{
    private const PAIRING_PATH = '#^([a-z][a-z0-9+.-]*://[^/?\#]*)?/(p|scripts/pair)/[^/?\#]+#i';

    public function scrubUrl(string $url): string
    {
        return parent::scrubUrl(preg_replace(self::PAIRING_PATH, '$1/$2/'.self::REDACTED, $url) ?? $url);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public function scrubArray(array $input, int $depth = 0): array
    {
        if ($depth === 0 && array_key_exists('pairing', $input)) {
            $input['pairing'] = self::REDACTED;
        }

        return parent::scrubArray($input, $depth);
    }
}
