<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_revisions', function (Blueprint $table) {
            // nullOnDelete, как у editor_id рядом — снимок переживает
            // удаление типа, просто теряет конкретную ссылку на него.
            // Без этих двух колонок правка кастомного поля была бы
            // неотменяемой через историю узла, в отличие от правки любого
            // встроенного поля.
            $table->foreignId('node_type_id')->nullable()->after('shape')
                ->constrained('node_types')->nullOnDelete();
            $table->json('custom_fields')->nullable()->after('node_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('node_revisions', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
            $table->dropConstrainedForeignId('node_type_id');
        });
    }
};
