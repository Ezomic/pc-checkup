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
        Schema::create('checklist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Machine::class)->constrained()->cascadeOnDelete();
            $table->string('item_key', 64);
            $table->string('outcome');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_entries');
    }
};
