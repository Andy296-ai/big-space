<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('shape')->default('circle')->after('color');
            $table->string('logo_path')->nullable()->after('shape');
            // Заполнен только у узла-пользователя в Admin-пространстве.
            $table->foreignId('linked_user_id')->nullable()->after('tree_root_id')
                ->constrained('users')->nullOnDelete();
            // Заполнен только у узла-пространства под узлом пользователя.
            $table->foreignId('linked_space_id')->nullable()->after('linked_user_id')
                ->constrained('spaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_space_id');
            $table->dropConstrainedForeignId('linked_user_id');
            $table->dropColumn(['shape', 'logo_path']);
        });
    }
};
