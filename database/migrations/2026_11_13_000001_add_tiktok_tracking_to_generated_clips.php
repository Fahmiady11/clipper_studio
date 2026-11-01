<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            // Processing poll: when the file reached TikTok and when its status was last asked
            $table->timestamp('tiktok_uploaded_at')->nullable();
            $table->timestamp('tiktok_checked_at')->nullable();

            $table->index('tiktok_status');
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropIndex(['tiktok_status']);
            $table->dropColumn(['tiktok_uploaded_at', 'tiktok_checked_at']);
        });
    }
};
