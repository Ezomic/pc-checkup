<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('kind')->default('audit')->after('format_version');
            $table->foreignId('before_report_id')->nullable()->after('kind')->constrained('reports')->nullOnDelete();
            $table->json('fixes')->nullable()->after('flags');
            $table->json('benchmark')->nullable()->after('fixes');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('before_report_id');
            $table->dropColumn(['kind', 'fixes', 'benchmark']);
        });
    }
};
