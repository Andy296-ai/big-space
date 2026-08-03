<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->string('title')->default('');
            $table->text('description')->default('');
            $table->double('pos_x')->default(0);
            $table->double('pos_y')->default(0);
            $table->double('pos_z')->default(0);
            $table->integer('depth')->default(0);
            $table->string('color')->default('');
            $table->string('tags')->default('');
            $table->unsignedBigInteger('tree_root_id')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'pos_x', 'pos_y', 'pos_z']);
            $table->index(['space_id', 'depth']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->foreign('tree_root_id')->references('id')->on('nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
