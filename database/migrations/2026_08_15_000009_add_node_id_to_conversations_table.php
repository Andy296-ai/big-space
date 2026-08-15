<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('node_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Та же защита от дубля, что и у team_id — один разговор на узел
        // (см. 2026_08_15_000003_create_conversations_table.php).
        DB::statement("CREATE UNIQUE INDEX conversations_node_id_unique ON conversations (node_id) WHERE type = 'node'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS conversations_node_id_unique');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('node_id');
        });
    }
};
