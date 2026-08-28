<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // nullOnDelete — удаление типа "разтипизирует" узлы, а не уносит
            // их с собой (тот же принцип, что и у locked_by).
            $table->foreignId('node_type_id')->nullable()->after('shape')
                ->constrained('node_types')->nullOnDelete();
            // Значения кастомных полей ТОЛЬКО этого типа — встроенные поля
            // (title/description/tags/map/color/shape/logo/attachments)
            // сюда не попадают, у них свои колонки как и раньше.
            $table->json('custom_fields')->nullable()->after('node_type_id');
            // Подсказка для формы "добавить ребёнка" — тот же принцип
            // каскадных дефолтов, что у default_shape/default_color, но
            // без серверного fallback'а (см. §8 плана): фронт сам решает,
            // предзаполнять тип в форме или нет.
            $table->foreignId('default_node_type_id')->nullable()->after('custom_fields')
                ->constrained('node_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_node_type_id');
            $table->dropColumn('custom_fields');
            $table->dropConstrainedForeignId('node_type_id');
        });
    }
};
