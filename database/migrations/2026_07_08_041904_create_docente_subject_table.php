<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docente_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('docente_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('subject_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('parallel_id')
                ->constrained()
                ->onDelete('cascade');

            $table->boolean('status')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docente_subject');
    }
};
