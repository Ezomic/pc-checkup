<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChecklistItemResource extends JsonResource
{
    /**
     * @param  array{key: string, label: string, group: string, outcome: string|null, note: string|null}  $item
     */
    public function __construct(private readonly array $item)
    {
        parent::__construct($item);
    }

    /**
     * @return array{key: string, label: string, group: string, outcome: string|null, note: string|null}
     */
    public function toArray(Request $request): array
    {
        return $this->item;
    }
}
