<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('docente_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('subject_id')
                ->constrained()
                ->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_type', 50)->nullable(); // pdf, image, video, etc.
            $table->boolean('all_parallels')->default(false);
            $table->timestamps();
        });

        // Tabla pivote para materiales visibles en múltiples paralelos
        Schema::create('material_parallel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('parallel_id')
                ->constrained()
                ->onDelete('cascade');
            $table->unique(['material_id', 'parallel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_parallel');
        Schema::dropIfExists('materials');
    }
};
