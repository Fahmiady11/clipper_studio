<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            // Why autopilot could not book a YouTube slot for the approved clip (too long, no channel)
            $table->text('youtube_autopost_error')->nullable();
            // Processing poll: when the file reached YouTube and when its status was last asked
            $table->timestamp('youtube_uploaded_at')->nullable();
            $table->timestamp('youtube_checked_at')->nullable();
            $table->string('youtube_privacy', 16)->nullable(); // what YouTube reports: public | unlisted | private

            $table->index('youtube_status');
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropIndex(['youtube_status']);
            $table->dropColumn(['youtube_autopost_error', 'youtube_uploaded_at', 'youtube_checked_at', 'youtube_privacy']);
        });
    }
};
