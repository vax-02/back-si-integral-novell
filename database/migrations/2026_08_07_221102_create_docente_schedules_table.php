<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docente_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('docente_id')
                ->constrained()
                ->onDelete('cascade');

            $table->string('day', 20); // Lunes, Martes, ...
            $table->time('entry_time');

            $table->timestamps();

            $table->index(['docente_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docente_schedules');
    }
};
