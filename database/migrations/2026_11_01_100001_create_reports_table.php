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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Machine::class)->constrained()->cascadeOnDelete();
            $table->longText('markdown');
            $table->string('checksum', 64);
            $table->unsignedSmallInteger('format_version')->nullable();
            $table->json('flags');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['machine_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
