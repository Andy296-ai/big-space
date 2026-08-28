<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            // key — стабильный машинный идентификатор (не id), им же
            // ссылаются друг на друга узлы при экспорте/импорте пространства
            // (см. SpaceController::export()/import()) — id при импорте не
            // переживает, а key человекочитаем и стабилен между экспортами.
            $table->string('key');
            $table->string('label');
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            // Обычный json, не jsonb — тот же выбор, что уже сделан для
            // ActivityLog::meta: фильтрации/индексации внутри значений пока
            // не требуется, а апгрейд типа колонки — тривиальный ALTER
            // позже, если понадобится. Пустая схема = тип без кастомных
            // полей, ещё не настроен.
            $table->json('field_schema')->nullable();
            $table->timestamps();

            $table->unique(['space_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_types');
    }
};
