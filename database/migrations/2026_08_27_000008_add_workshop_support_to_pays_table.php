<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pays', function (Blueprint $table) {
            $table->foreignId('workshop_concept_id')->nullable()->after('concept_id')->constrained()->nullOnDelete();
            $table->enum('source', ['career', 'workshop'])->default('career')->after('workshop_concept_id');
        });
    }

    public function down(): void
    {
        Schema::table('pays', function (Blueprint $table) {
            $table->dropForeign(['workshop_concept_id']);
            $table->dropColumn(['workshop_concept_id', 'source']);
        });
    }
};
