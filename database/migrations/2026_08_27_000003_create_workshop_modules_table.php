<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->unsignedInteger('order');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_modules');
    }
};
