<?php

declare(strict_types=1);

namespace App\Enums;

enum BenchmarkMetric: string
{
    case CpuSingle = 'cpu_single_ms';
    case CpuMulti = 'cpu_multi_ms';
    case Memory = 'memory_mb_s';
    case DiskWrite = 'disk_write_mb_s';
    case DiskRead = 'disk_read_mb_s';
    case NetDown = 'net_down_mbps';
    case NetUp = 'net_up_mbps';

    public function label(): string
    {
        return match ($this) {
            self::CpuSingle => 'CPU single-core',
            self::CpuMulti => 'CPU multi-core',
            self::Memory => 'Memory',
            self::DiskWrite => 'Disk write',
            self::DiskRead => 'Disk read',
            self::NetDown => 'Download',
            self::NetUp => 'Upload',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::CpuSingle, self::CpuMulti => 'ms',
            self::Memory, self::DiskWrite, self::DiskRead => 'MB/s',
            self::NetDown, self::NetUp => 'Mbps',
        };
    }

    public function higherIsBetter(): bool
    {
        return ! in_array($this, [self::CpuSingle, self::CpuMulti], true);
    }

    public function healthy(): int
    {
        return match ($this) {
            self::CpuSingle => 800,
            self::CpuMulti => 500,
            self::Memory => 8000,
            self::DiskWrite, self::DiskRead => 500,
            self::NetDown => 100,
            self::NetUp => 50,
        };
    }

    public function poor(): int
    {
        return match ($this) {
            self::CpuSingle => 2000,
            self::CpuMulti => 1500,
            self::Memory => 2000,
            self::DiskWrite, self::DiskRead => 100,
            self::NetDown => 20,
            self::NetUp => 10,
        };
    }

    /**
     * @return 'healthy'|'fair'|'poor'
     */
    public function rate(int|float $value): string
    {
        if ($this->higherIsBetter()) {
            return match (true) {
                $value >= $this->healthy() => 'healthy',
                $value < $this->poor() => 'poor',
                default => 'fair',
            };
        }

        return match (true) {
            $value <= $this->healthy() => 'healthy',
            $value > $this->poor() => 'poor',
            default => 'fair',
        };
    }

    public function improved(int|float $delta): ?bool
    {
        if ($delta == 0) {
            return null;
        }

        return ($delta > 0) === $this->higherIsBetter();
    }
}
