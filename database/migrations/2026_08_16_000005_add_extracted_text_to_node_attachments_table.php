<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_attachments', function (Blueprint $table) {
            // Заполняется один раз при загрузке (PDF/DOCX) — не на каждый
            // поиск, см. AttachmentController::extractSearchableText().
            $table->longText('extracted_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_attachments', function (Blueprint $table) {
            $table->dropColumn('extracted_text');
        });
    }
};
