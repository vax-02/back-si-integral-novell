<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('workshop_edition_id')->constrained()->onDelete('cascade');
            $table->date('enrolled');
            $table->string('code')->unique();
            $table->enum('status', ['Activo', 'Completado', 'Retirado'])->default('Activo');
            $table->timestamps();
            $table->unique(['student_id', 'workshop_edition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_enrollments');
    }
};
