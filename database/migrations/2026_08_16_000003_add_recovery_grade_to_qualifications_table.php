<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->decimal('recovery_grade', 5, 2)->nullable()->after('final_grade');
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropColumn('recovery_grade');
        });
    }
};
