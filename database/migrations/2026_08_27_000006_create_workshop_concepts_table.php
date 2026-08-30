<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_edition_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['Inscripcion', 'Cuota', 'Otro']);
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_concepts');
    }
};
