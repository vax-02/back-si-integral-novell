<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_subjects', function (Blueprint $table) {
            $table->enum('status', ['Registrado', 'Aprobado', 'Reprobado', 'Falta'])
                ->default('Registrado')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_subjects', function (Blueprint $table) {
            $table->enum('status', ['Registrado', 'Aprobado', 'Falta'])
                ->default('Registrado')
                ->change();
        });
    }
};
