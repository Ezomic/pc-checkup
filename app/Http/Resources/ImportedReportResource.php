<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Report $resource
 */
final class ImportedReportResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'machine_id' => $this->resource->machine_id,
            'report_id' => $this->resource->id,
            'created' => $this->resource->wasRecentlyCreated,
        ];
    }
}
