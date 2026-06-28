<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations .
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('degree_id')
                ->constrained()
                ->onDelete('cascade');

            $table->enum('turno', ['Mañana', 'Tarde','Noche'])->default('Mañana');

            $table->string('matricula', 100);
            
            $table->tinyInteger('birth_certificate')->default(1);
            $table->tinyInteger('school_diploma')->default(1);
            $table->tinyInteger('carnet')->default(1);
            
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
