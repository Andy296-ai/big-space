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
        Schema::table('nodes', function (Blueprint $table) {
            // Только у корневых узлов (depth = 0) — форма/цвет по умолчанию
            // для новых дочерних узлов этого дерева.
            $table->string('default_shape')->nullable()->after('shape');
            $table->string('default_color')->nullable()->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['default_shape', 'default_color']);
        });
    }
};
