<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One Meta login: the user token we got from Facebook Login (later also Threads login)
        Schema::create('meta_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16)->default('facebook'); // facebook | threads
            $table->string('provider_user_id');
            $table->string('name')->nullable();
            $table->text('access_token'); // encrypted cast; long-lived user token
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable(); // granted permissions, comma separated
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'provider_user_id']);
        });

        // Where clips get published: a Facebook Page now; an Instagram account or Threads profile later
        Schema::create('meta_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16); // facebook | instagram | threads
            $table->string('external_id'); // Page id, IG user id, Threads user id
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->text('picture_url')->nullable();
            $table->text('access_token')->nullable(); // encrypted cast; Page token. Null: use the account's token
            $table->json('details')->nullable(); // platform extras, e.g. the Page tasks
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'external_id']);
        });

        // A clip's way to one platform: what the upload job and the status poller report
        Schema::create('clip_publications', function (Blueprint $table) {
            $table->id();
            $table->uuid('generated_clip_id');
            $table->foreign('generated_clip_id')->references('id')->on('generated_clips')->cascadeOnDelete();
            $table->string('platform', 16);
            $table->unsignedBigInteger('target_id')->nullable();
            // skipped (autopilot could not book it) | queued | uploading | processing | published | failed
            $table->string('status', 16);
            $table->string('external_id')->nullable(); // the platform's video / media id
            $table->text('url')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['generated_clip_id', 'platform']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_publications');
        Schema::dropIfExists('meta_targets');
        Schema::dropIfExists('meta_accounts');
    }
};
