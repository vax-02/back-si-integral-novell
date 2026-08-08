<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('docente_id')
                ->nullable()
                ->constrained()
                ->onDelete('cascade');

            $table->string('biometric_pin', 20);
            $table->dateTime('clock_at');
            $table->tinyInteger('verify')->default(0);
            $table->tinyInteger('workcode')->default(0);

            $table->timestamps();

            $table->unique(['biometric_pin', 'clock_at'], 'unique_pin_clock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
