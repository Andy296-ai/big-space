<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_root')->default(false)->after('password');
        });

        // Учётку root защищаем флагом, а не сравнением имени — так её нельзя
        // случайно "потерять" переименованием.
        DB::table('users')
            ->where('name', config('auth.root.username'))
            ->update(['is_root' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_root');
        });
    }
};
