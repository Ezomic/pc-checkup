<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MachineNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property MachineNote $resource
 */
final class NoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'body' => $this->resource->body,
            'created_at' => $this->resource->created_at,
        ];
    }
}
