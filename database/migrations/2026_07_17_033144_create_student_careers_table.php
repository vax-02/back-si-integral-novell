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
        Schema::create('student_careers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('career_id')
                ->constrained()
                ->onDelete('cascade');
            
            $table->date('enrolled');
            $table->string('matricula');
            
            $table->enum('turno', ['Mañana', 'Tarde','Noche'])->default('Mañana');


            $table->enum('status',['Activo','Egresado','Suspendido','Retirado'])->default('Activo');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_careers');
    }
};
