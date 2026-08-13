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
            // Null у существующих admin-уровневых записей (создание/удаление
            // пользователей и т.п.) — они не привязаны к рабочему пространству.
            $table->foreignId('space_id')->nullable()->after('actor_id')->constrained()->nullOnDelete();

            $table->index(['space_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('space_id');
        });
    }
};
