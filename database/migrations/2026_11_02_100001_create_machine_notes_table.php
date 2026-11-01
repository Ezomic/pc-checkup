<?php

declare(strict_types=1);

use App\Models\Machine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Machine::class)->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_notes');
    }
};
