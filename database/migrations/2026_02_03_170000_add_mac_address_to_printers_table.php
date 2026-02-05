<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('mac_address')->nullable()->unique()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropUnique(['mac_address']);
            $table->dropColumn('mac_address');
        });
    }
};
