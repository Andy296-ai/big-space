<?php

use App\Models\Conversation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Сеет единственный общий разговор один раз — тот же приём, что и create_root_user. */
    public function up(): void
    {
        if (DB::table('conversations')->where('type', Conversation::TYPE_GLOBAL)->exists()) {
            return;
        }

        $globalId = DB::table('conversations')->insertGetId([
            'type' => Conversation::TYPE_GLOBAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        $rows = DB::table('users')->pluck('id')->map(fn ($id) => [
            'conversation_id' => $globalId,
            'user_id' => $id,
            'last_read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('conversation_participants')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::table('conversations')->where('type', Conversation::TYPE_GLOBAL)->delete();
    }
};
