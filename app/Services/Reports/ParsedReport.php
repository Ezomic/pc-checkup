<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Machines\MachineIdentity;

final readonly class ParsedReport
{
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
        return $this->identity()->usableSerial();
    }

    public function usableUuid(): ?string
    {
        return $this->identity()->usableUuid();
    }

    public function identityKey(): ?string
    {
        return $this->identity()->key();
    }

    private function identity(): MachineIdentity
    {
        return new MachineIdentity($this->serial, $this->hardwareUuid);
    }
}
