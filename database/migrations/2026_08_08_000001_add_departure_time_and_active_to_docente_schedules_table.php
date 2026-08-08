<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docente_schedules', function (Blueprint $table) {
            $table->time('departure_time')->nullable()->after('entry_time');
            $table->boolean('is_active')->default(true)->after('departure_time');
        });
    }

    public function down(): void
    {
        Schema::table('docente_schedules', function (Blueprint $table) {
            $table->dropColumn(['departure_time', 'is_active']);
        });
    }
};
