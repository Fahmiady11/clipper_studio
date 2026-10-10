<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops schema nothing reads anymore:
 * - clip_jobs / clip_batches: the removed v1 clip pipeline
 * - personal_access_tokens: Sanctum was never used (the API uses the web session)
 * - users.avatar, users.email_verified_at: no avatar upload, no email verification
 * - discovered_videos.title/channel/duration_seconds: written but never read
 *   (the title lives on clip_projects.video_title)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('clip_jobs');
        Schema::dropIfExists('clip_batches');
        Schema::dropIfExists('personal_access_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'email_verified_at']);
        });

        Schema::table('discovered_videos', function (Blueprint $table) {
            $table->dropColumn(['title', 'channel', 'duration_seconds']);
        });
    }

    public function down(): void
    {
        Schema::table('discovered_videos', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->string('channel')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable();
            $table->string('avatar')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('clip_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('youtube_url', 500);
            $table->string('video_id', 20)->nullable();
            $table->string('layout_mode', 30);
            $table->tinyInteger('clip_count')->default(3);
            $table->string('status', 20)->default('pending')->index();
            $table->text('error_msg')->nullable();
            $table->timestamps();
        });

        Schema::create('clip_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('youtube_url', 500);
            $table->string('layout_mode', 30)->nullable();
            $table->integer('start_time');
            $table->integer('end_time');
            $table->string('status', 20)->default('pending')->index();
            $table->string('file_path', 500)->nullable();
            $table->text('error_msg')->nullable();
            $table->text('gemini_hook')->nullable();
            $table->text('gemini_caption')->nullable();
            $table->json('gemini_hashtags')->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('overlay_text')->nullable();
            $table->string('overlay_position')->nullable()->default('bottom_right');
            $table->string('overlay_color')->nullable()->default('white');
            $table->integer('overlay_fontsize')->nullable()->default(36);
            $table->boolean('auto_caption')->default(false);
            $table->string('caption_language', 10)->nullable();
            $table->timestamps();
            $table->timestamp('expires_at')->nullable();
        });
    }
};
