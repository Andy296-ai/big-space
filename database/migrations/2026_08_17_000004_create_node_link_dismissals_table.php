<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_link_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            // node_a_id < node_b_id всегда (нормализованный порядок,
            // проставляется в LinkSuggestionService) — иначе одна и та же
            // пара могла бы осесть в таблице дважды в разном порядке, и
            // отклонение "A→B" не помешало бы снова предложить "B→A".
            $table->foreignId('node_a_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('node_b_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('dismissed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('dismissed_at')->useCurrent();

            $table->unique(['node_a_id', 'node_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_link_dismissals');
    }
};
