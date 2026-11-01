<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Machine;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    protected $model = Photo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'machine_id' => Machine::factory(),
            'path' => 'factory/'.Str::uuid()->toString().'.jpg',
            'caption' => null,
            'width' => 800,
            'height' => 600,
            'bytes' => 1234,
        ];
    }
}
