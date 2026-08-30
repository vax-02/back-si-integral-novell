<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('workshop_module_id')->constrained()->onDelete('cascade');
            $table->foreignId('workshop_edition_id')->constrained()->onDelete('cascade');
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'workshop_module_id', 'workshop_edition_id'], 'wk_grade_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_grades');
    }
};
