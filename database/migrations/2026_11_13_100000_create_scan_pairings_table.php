<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_pairings', function (Blueprint $table) {
            $table->id();
            $table->string('pairing_hash', 64)->unique();
            $table->string('secret_hash', 64);
            $table->string('check_code', 4);
            $table->timestamp('expires_at');
            $table->foreignIdFor(User::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Client::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Machine::class)->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_pairings');
    }
};
