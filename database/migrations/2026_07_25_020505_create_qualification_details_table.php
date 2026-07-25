<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar parallel_id y final_grade a qualifications
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('parallel_id')
                ->nullable()
                ->after('course_id')
                ->constrained()
                ->onDelete('cascade');

            $table->decimal('final_grade', 5, 2)
                ->nullable()
                ->after('qualification');
        });

        // Crear tabla qualification_details
        Schema::create('qualification_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualification_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('evaluation_column_id')
                ->constrained()
                ->onDelete('cascade');
            $table->decimal('grade', 5, 2)->nullable();
            $table->timestamps();

            // Un detalle por qualification + evaluation_column
            $table->unique(['qualification_id', 'evaluation_column_id'], 'uq_qual_detail_col');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_details');

        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropForeign(['parallel_id']);
            $table->dropColumn('parallel_id');
            $table->dropColumn('final_grade');
        });
    }
};