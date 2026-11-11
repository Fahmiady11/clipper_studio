<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only the tiktok row reads it: 'inbox' sends a draft, 'direct' posts it. Existing rows stay inbox.
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('tiktok_mode', 10)->default('inbox')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('tiktok_mode');
        });
    }
};
