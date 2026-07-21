<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('parallel_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('course_id')
                ->constrained()
                ->onDelete('cascade');
            $table->string('name'); // Ej: "Primer parcial", "Segundo parcial", "Trabajo final"
            $table->decimal('weight', 5, 2); // Ej: 0.30 (30%)
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        // Agregar evaluation_column_id a qualifications
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('evaluation_column_id')
                ->nullable()
                ->constrained()
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropForeign(['evaluation_column_id']);
            $table->dropColumn('evaluation_column_id');
        });
        Schema::dropIfExists('evaluation_columns');
    }
};