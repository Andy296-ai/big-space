<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // Географическая точка узла — отдельно от pos_x / pos_y, которые
            // задают положение в сцене. Пусто — блок карты просто не рисуется.
            $table->double('map_lat')->nullable()->after('tags');
            $table->double('map_lon')->nullable()->after('map_lat');
            $table->string('map_title')->nullable()->after('map_lon');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['map_lat', 'map_lon', 'map_title']);
        });
    }
};
