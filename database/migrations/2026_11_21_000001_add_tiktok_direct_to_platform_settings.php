<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            // TikTok direct-post choices for autopilot: privacy_level, allow_comment, allow_duet, allow_stitch. Null means the defaults.
            $table->json('tiktok_direct')->nullable()->after('tiktok_mode');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('tiktok_direct');
        });
    }
};
