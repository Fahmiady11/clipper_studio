<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for every platform connected through the SocialPublisher contract
 * (Instagram first). tiktok_accounts and youtube_accounts stay as they are:
 * their redirect URIs are registered with the providers and YouTube channel
 * verification reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('external_id');          // the account's id on that platform
            $table->string('display_name')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('access_token');           // encrypted cast
            $table->text('refresh_token')->nullable(); // encrypted cast
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->text('scope')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'external_id']);
        });

        Schema::table('generated_clips', function (Blueprint $table) {
            $table->foreignId('instagram_account_id')->nullable();
            $table->string('instagram_media_id')->nullable();
            $table->text('instagram_url')->nullable();
            $table->string('instagram_status', 32)->nullable();
            $table->text('instagram_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropColumn(['instagram_account_id', 'instagram_media_id', 'instagram_url', 'instagram_status', 'instagram_error']);
        });
        Schema::dropIfExists('social_accounts');
    }
};
