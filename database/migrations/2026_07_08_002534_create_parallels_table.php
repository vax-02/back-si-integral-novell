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
        Schema::create('parallels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained()
                ->onDelete('cascade');

            $table->string('paralelo',2);
            $table->integer('limit');
            $table->enum('turno', ['Mañana', 'Tarde','Noche'])->default('Mañana');
            $table->boolean('status')->default(true);

            $table->unique(['course_id', 'paralelo', 'turno']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parallels');
    }
};
