<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            // Одно вложение на сообщение (не N, как у NodeAttachment) — макет
            // не предполагает несколько файлов или файл+подпись в одном сообщении.
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('label')->default('');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('format', 8)->default('');
            // Определяется по содержимому файла на сервере (не по расширению
            // и не по Content-Type от клиента) — см. MessageController::store().
            $table->string('mime')->default('');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
