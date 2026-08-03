<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            // file — предлагается скачать, link — открыть.
            $table->string('kind')->default('file');
            $table->string('label')->default('');
            $table->string('url');
            // Короткий бейдж справа: PDF, ZIP, MD…
            $table->string('format', 8)->default('');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['node_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_attachments');
    }
};
