<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('identity_key');
            $table->string('serial')->nullable();
            $table->string('hardware_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('os')->nullable();
            $table->string('device_type')->nullable();
            $table->string('model')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['user_id', 'identity_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
