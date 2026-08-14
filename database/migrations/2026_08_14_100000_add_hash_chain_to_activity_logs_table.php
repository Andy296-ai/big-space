<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // Обе колонки nullable: у записей, сделанных до этой фичи (и у
            // тех, что позже вышли за срок хранения и были подрезаны),
            // hash = null — ActivityLog::verifyChain() воспринимает это
            // одинаково («доверенная точка отсчёта») в обоих случаях.
            $table->string('prev_hash', 64)->nullable()->after('meta');
            $table->string('hash', 64)->nullable()->after('prev_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'hash']);
        });
    }
};
