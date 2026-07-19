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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('parallel_id')
                ->constrained()
                ->onDelete('cascade');
            
            $table->string('day', 20); // Lunes, Martes...
            $table->time('start_time');
            $table->time('end_time');
            
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamps();
            
            // Un paralelo no puede tener dos materias en el mismo día y horario
            $table->unique(['parallel_id', 'day', 'start_time', 'end_time'], 'unique_schedule_slot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};