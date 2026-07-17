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
        Schema::create('concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')
                ->constrained()
                ->nullable()
                ->onDelete('cascade');
            $table->enum('type',["Matricula","Mensualidad","Otro"]);
            $table->string('description')->nullable(); //Llenar en caso de type:otro
            $table->year('gestion');
            $table->enum('semestre',[1,2])->nullable(); //Semestre 1 o 2

            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->unique(
                ['career_id', 'type', 'gestion', 'semestre'],
                'concept_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('concepts');
    }
};
