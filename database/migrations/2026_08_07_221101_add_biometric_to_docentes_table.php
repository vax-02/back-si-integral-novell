<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docentes', function (Blueprint $table) {
            $table->string('biometric_pin', 20)->nullable()->unique()->after('certificate');
            $table->unsignedInteger('tolerance_minutes')->default(5)->after('biometric_pin');
        });
    }

    public function down(): void
    {
        Schema::table('docentes', function (Blueprint $table) {
            $table->dropUnique(['biometric_pin']);
            $table->dropColumn(['biometric_pin', 'tolerance_minutes']);
        });
    }
};
