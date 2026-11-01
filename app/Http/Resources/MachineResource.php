<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Machine;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Machine $resource
 */
final class MachineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $machine = $this->resource;

        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'serial' => $machine->serial,
            'hardware_uuid' => $machine->hardware_uuid,
            'os' => $machine->os,
            'device_type' => $machine->device_type,
            'model' => $machine->model,
            'status' => $machine->status->value,
            'reports_count' => $machine->reports_count ?? 0,
            'last_report_at' => $machine->last_report_at === null ? null : CarbonImmutable::parse($machine->last_report_at, 'UTC'),
            'created_at' => $machine->created_at,
        ];
    }
}
