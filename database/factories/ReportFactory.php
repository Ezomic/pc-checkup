<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Machine;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $markdown = "# Mac checkup: Test\n\n".fake()->paragraph()."\n";

        return [
            'machine_id' => Machine::factory(),
            'markdown' => $markdown,
            'checksum' => hash('sha256', $markdown),
            'format_version' => 1,
            'flags' => [],
        ];
    }
}
