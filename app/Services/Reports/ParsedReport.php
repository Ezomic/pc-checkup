<?php

declare(strict_types=1);

namespace App\Services\Reports;

final readonly class ParsedReport
{
    private const MAX_SERIAL_LENGTH = 100;

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

        // A run of one repeated character (0000000, FFFFFFF) is a blank firmware default.
        return preg_match('/^(.)\1*$/u', $serial) === 1 ? null : $serial;
    }

    public function identityKey(): ?string
    {
        $serial = $this->usableSerial();

        if ($serial !== null) {
            return 'serial:'.mb_strtoupper($serial);
        }

        return $this->hardwareUuid === null ? null : 'uuid:'.mb_strtolower($this->hardwareUuid);
    }
}
