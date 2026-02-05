<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->unsignedInteger('last_check_duration_ms')->nullable()->after('last_checked_at');
            $table->unsignedInteger('avg_check_duration_ms')->nullable()->after('last_check_duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn(['last_check_duration_ms', 'avg_check_duration_ms']);
        });
    }
};
