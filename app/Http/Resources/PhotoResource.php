<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata only: the image itself sits behind the signed in web session.
 *
 * @property Photo $resource
 */
final class PhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'caption' => $this->resource->caption,
            'width' => $this->resource->width,
            'height' => $this->resource->height,
            'bytes' => $this->resource->bytes,
            'created_at' => $this->resource->created_at,
        ];
    }
}
