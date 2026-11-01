<?php

declare(strict_types=1);

namespace App\Services\Reports;

final readonly class ParsedReport
{
    private const MAX_SERIAL_LENGTH = 100;

    private const MAX_UUID_LENGTH = 100;

    private const PLACEHOLDER_SERIALS = [
        'to be filled by o.e.m.',
        'default string',
        'system serial number',
        'not specified',
        'not applicable',
        'none',
        'unknown',
        'n/a',
        '123456789',
    ];

    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public ?string $name,
        public ?int $formatVersion,
        public ?string $os,
        public ?string $deviceType,
        public ?string $serial,
        public ?string $hardwareUuid,
        public ?string $model,
        public array $flags,
    ) {}

    public function usableSerial(): ?string
    {
        $serial = $this->serial;

        if ($serial === null || mb_strlen($serial) > self::MAX_SERIAL_LENGTH) {
            return null;
        }

        if (in_array(mb_strtolower($serial), self::PLACEHOLDER_SERIALS, true)) {
            return null;
        }

        return $this->isBlankDefault($serial) ? null : $serial;
    }

    public function usableUuid(): ?string
    {
        $uuid = $this->hardwareUuid;

        if ($uuid === null || mb_strlen($uuid) > self::MAX_UUID_LENGTH) {
            return null;
        }

        return $this->isBlankDefault(str_replace('-', '', $uuid)) ? null : $uuid;
    }

    public function identityKey(): ?string
    {
        $serial = $this->usableSerial();

        if ($serial !== null) {
            return 'serial:'.mb_strtoupper($serial);
        }

        $uuid = $this->usableUuid();

        return $uuid === null ? null : 'uuid:'.mb_strtolower($uuid);
    }

    // A run of one repeated character (0000000, FFFFFFF) is what firmware reports when nothing was set.
    private function isBlankDefault(string $value): bool
    {
        return $value === '' || preg_match('/^(.)\1*$/u', $value) === 1;
    }
}
