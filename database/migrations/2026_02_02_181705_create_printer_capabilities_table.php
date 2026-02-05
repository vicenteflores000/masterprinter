<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('printer_capabilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('printer_id')
                ->constrained('printers')
                ->cascadeOnDelete();

            $table->boolean('can_read_levels')->default(false);
            $table->boolean('can_read_states')->default(false);
            $table->boolean('supports_printer_mib')->default(false);

            $table->text('notes')->nullable();
            $table->timestamp('detected_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_capabilities');
    }
};
