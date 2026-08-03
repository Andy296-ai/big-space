<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('parent_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('nodes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['space_id', 'parent_id', 'child_id']);
            $table->index(['space_id', 'parent_id']);
            $table->index(['space_id', 'child_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edges');
    }
};
