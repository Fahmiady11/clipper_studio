<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('generated_clip_id');
            $table->foreign('generated_clip_id')->references('id')->on('generated_clips')->cascadeOnDelete();
            $table->string('platform', 16); // tiktok | youtube
            $table->unsignedBigInteger('account_id');
            $table->timestamp('scheduled_at'); // UTC
            $table->string('status', 16)->default('scheduled'); // scheduled | publishing | queued | failed
            $table->json('options')->nullable(); // per-platform upload settings
            $table->text('error')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['user_id', 'scheduled_at']);
            $table->index(['generated_clip_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
