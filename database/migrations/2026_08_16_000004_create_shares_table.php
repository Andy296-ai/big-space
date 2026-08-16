<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            // null = ссылка на всё пространство; иначе — на поддерево этого узла.
            $table->foreignId('node_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 48)->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Одна активная ссылка на поддерево — обычный unique справляется
            // (не-null значения по-прежнему конфликтуют друг с другом, а
            // NULL'ы — это как раз строки whole-space, для них своя проверка ниже).
            $table->unique('node_id');
        });

        // NULL != NULL в SQL — обычный unique(space_id, node_id) пропустил бы
        // несколько строк с node_id = NULL для одного space_id. Тот же приём,
        // что уже дважды использован для conversations.team_id/node_id.
        DB::statement('CREATE UNIQUE INDEX shares_space_whole_unique ON shares (space_id) WHERE node_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};
