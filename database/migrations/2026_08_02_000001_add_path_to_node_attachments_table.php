<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_attachments', function (Blueprint $table) {
            // Путь в приватном хранилище — только у загруженных файлов.
            // У внешних ссылок он пуст, а адрес лежит в url.
            $table->string('path')->nullable()->after('url');
            $table->unsignedBigInteger('size')->nullable()->after('path');
        });

        // url обязателен только для ссылок, поэтому пересобираем колонку.
        Schema::table('node_attachments', function (Blueprint $table) {
            $table->string('url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('node_attachments', function (Blueprint $table) {
            $table->dropColumn(['path', 'size']);
        });
    }
};
