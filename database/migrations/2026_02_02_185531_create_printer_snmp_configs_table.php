<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('printer_snmp_configs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('printer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('version')->default('2c');   // 1 | 2c | 3
            $table->string('community')->default('public');

            // reservado para SNMPv3 futuro
            $table->string('username')->nullable();
            $table->string('auth_protocol')->nullable();
            $table->string('auth_password')->nullable();
            $table->string('priv_protocol')->nullable();
            $table->string('priv_password')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printer_snmp_configs');
    }
};
