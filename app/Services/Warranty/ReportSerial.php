<?php

declare(strict_types=1);

namespace App\Services\Warranty;

use App\Services\Reports\ReportSections;

final readonly class ReportSerial
{
    private const MIN_LENGTH = 5;

    private const MAX_LENGTH = 30;

    private const ALLOWED = '/\A[A-Za-z0-9-]+\z/';

    private const PLACEHOLDERS = [
        'tobefilledbyoem', 'oem', 'defaultstring', 'systemserialnumber', 'chassisserialnumber', 'notspecified',
        'notapplicable', 'notavailable', 'notdefined', 'none', 'unknown', 'na', 'invalid', 'serialnumber',
        'systemproductname', '123456789', '0123456789',
    ];

    public function from(string $markdown): ?string
    {
        $value = ReportSections::parse($markdown)->header('serial');

        return $value === null ? null : $this->clean($value);
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);
        $letters = (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));

        return match (true) {
            preg_match(self::ALLOWED, $value) !== 1 => null,
            strlen($value) > self::MAX_LENGTH => null,
            strlen($letters) < self::MIN_LENGTH => null,
            in_array($letters, self::PLACEHOLDERS, true) => null,
            preg_match('/\A(.)\1*\z/', $letters) === 1 => null,
            default => $value,
        };
    }
}
