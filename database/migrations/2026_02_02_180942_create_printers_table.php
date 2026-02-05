<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();

            $table->string('ip')->unique();
            $table->string('hostname')->nullable();

            $table->string('brand')->nullable();
            $table->string('model')->nullable();

            $table->string('sys_object_id')->nullable();

            $table->enum('monitoring_profile', [
                'level_real',
                'estado',
                'desconocido'
            ])->default('desconocido');

            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
