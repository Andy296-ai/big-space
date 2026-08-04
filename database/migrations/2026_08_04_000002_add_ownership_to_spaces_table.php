<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_admin')->default(false)->after('structure');
        });

        $rootId = DB::table('users')->where('is_root', true)->value('id');

        if ($rootId === null) {
            return;
        }

        // До этой миграции у пространств не было владельца — все они root'а.
        DB::table('spaces')->update(['user_id' => $rootId]);

        // Служебное пространство для управления пользователями: они
        // добавляются в него как обычные узлы (см. Node::linked_user_id).
        if (! DB::table('spaces')->where('slug', 'admin')->exists()) {
            DB::table('spaces')->insert([
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Users of this system, each as a node.',
                'structure' => 'tree',
                'user_id' => $rootId,
                'is_admin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('is_admin');
        });
    }
};
