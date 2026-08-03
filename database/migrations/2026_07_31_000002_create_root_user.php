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
