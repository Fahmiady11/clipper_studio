<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New sign-ups wait for the main admin's approval. Everyone who already has an
 * account stays approved, and the oldest account becomes the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->timestamp('approved_at')->nullable()->after('is_admin');
        });

        DB::table('users')->update(['approved_at' => now()]);
        $first = DB::table('users')->orderBy('id')->value('id');
        if ($first !== null) {
            DB::table('users')->where('id', $first)->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'approved_at']);
        });
    }
};
