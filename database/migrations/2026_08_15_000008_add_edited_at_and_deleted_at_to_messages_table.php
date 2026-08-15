<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            // Не Eloquent SoftDeletes — тот прячет строку из ЛЮБОГО запроса по
            // умолчанию, а здесь root должен продолжать видеть настоящее
            // содержимое. Ручная колонка + фильтрация в сериализации
            // (MessageController) даёт полный контроль по роли смотрящего.
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};
