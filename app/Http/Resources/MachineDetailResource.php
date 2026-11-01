<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Machine;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Machine $resource
 */
final class MachineDetailResource extends JsonResource
{
    /**
     * @param  list<array{key: string, label: string, group: string, outcome: string|null, note: string|null}>  $checklist
     */
    public function __construct(Machine $machine, private readonly ?Report $report, private readonly array $checklist)
    {
        parent::__construct($machine);
    }

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
            'latest_report' => $this->report === null ? null : new ReportResource($this->report),
            'checklist' => $this->checklist,
            'notes' => NoteResource::collection($machine->notes()->latest()->latest('id')->get()),
            'photos' => PhotoResource::collection($machine->photos()->oldest()->oldest('id')->get()),
        ];
    }
}
