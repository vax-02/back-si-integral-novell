<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pays', function (Blueprint $table) {
            $table->enum('payment_method', ['efectivo', 'qr'])->default('efectivo')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('pays', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
