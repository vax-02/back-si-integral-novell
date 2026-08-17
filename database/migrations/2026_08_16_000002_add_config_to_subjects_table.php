<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->decimal('theory_weight', 5, 2)->default(0.30)->after('status');
            $table->decimal('practice_weight', 5, 2)->default(0.70)->after('theory_weight');
            $table->integer('num_parciales')->default(2)->after('practice_weight');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['theory_weight', 'practice_weight', 'num_parciales']);
        });
    }
};
