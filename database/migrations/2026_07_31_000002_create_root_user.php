<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // name служит логином, поэтому он должен быть уникальным.
        Schema::table('users', function (Blueprint $table) {
            $table->unique('name');
        });

        $username = config('auth.root.username');

        if (DB::table('users')->where('name', $username)->exists()) {
            return;
        }

        // Дефолтный пароль (r@@t00) закоммичен в config/auth.php открытым
        // текстом — это нормально для локальной разработки, но за пределами
        // local/testing root ни в коем случае не должен получить именно его.
        if (! config('auth.root.password_explicitly_set') && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'ROOT_PASSWORD не задан в .env. За пределами local/testing нельзя '.
                'сеять root с дефолтным паролем из config/auth.php (он закоммичен в git) '.
                '— задайте ROOT_PASSWORD перед запуском миграций.',
            );
        }

        DB::table('users')->insert([
            'name' => $username,
            'email' => config('auth.root.email'),
            'password' => Hash::make(config('auth.root.password')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('name', config('auth.root.username'))->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
