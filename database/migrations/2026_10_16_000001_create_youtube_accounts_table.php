<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel_id');
            $table->string('title')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('access_token');  // encrypted cast
            $table->text('refresh_token'); // encrypted cast
            $table->timestamp('access_expires_at');
            $table->text('scope')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'channel_id']);
        });

        Schema::table('generated_clips', function (Blueprint $table) {
            $table->foreignId('youtube_account_id')->nullable();
            $table->string('youtube_video_id')->nullable();
            $table->string('youtube_status', 32)->nullable();
            $table->text('youtube_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropColumn(['youtube_account_id', 'youtube_video_id', 'youtube_status', 'youtube_error']);
        });
        Schema::dropIfExists('youtube_accounts');
    }
};
