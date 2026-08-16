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
        Schema::create('student_convalidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('career_id')
                ->constrained()
                ->onDelete('cascade');

            $table->enum('type', ['BTH', 'Tecnico_Medio']);
            $table->tinyInteger('start_level')->unsigned();
            $table->timestamps();

            $table->unique(['student_id', 'career_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_convalidations');
    }
};
