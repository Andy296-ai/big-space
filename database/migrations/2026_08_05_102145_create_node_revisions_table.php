<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('node_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            // Кто редактировал — может быть удалён позже, запись переживает это.
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();

            // Снимок состояния узла ДО правки — то, к чему вернёт restore.
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('tags')->nullable();
            $table->string('shape')->nullable();
            $table->float('map_lat')->nullable();
            $table->float('map_lon')->nullable();
            $table->string('map_title')->nullable();
            $table->float('pos_x')->nullable();
            $table->float('pos_y')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['node_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_revisions');
    }
};
