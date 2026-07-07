<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subjects', 'sigla')) {
            return;
        }

        $indexes = DB::select('SHOW INDEX FROM subjects');
        $hasUniqueSigla = collect($indexes)->contains(fn ($index) => $index->Key_name === 'subjects_sigla_unique');

        if (! $hasUniqueSigla) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->unique('sigla');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subjects', 'sigla')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->dropUnique(['sigla']);
            });
        }
    }
};
