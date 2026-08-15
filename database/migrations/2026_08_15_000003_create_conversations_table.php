<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index('type');
        });

        // Защита от дубля командного разговора на уровне БД — подстраховка,
        // а не основная гарантия (та — что только TeamProvisioner когда-либо
        // вставляет type=team, и делает это ровно один раз). Синтаксис
        // частичного уникального индекса одинаков на Postgres и SQLite
        // (тестовая БД по phpunit.xml).
        DB::statement("CREATE UNIQUE INDEX conversations_team_id_unique ON conversations (team_id) WHERE type = 'team'");
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
