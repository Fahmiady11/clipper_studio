<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_stats', function (Blueprint $table) {
            $table->id();
            $table->uuid('generated_clip_id');
            $table->foreign('generated_clip_id')->references('id')->on('generated_clips')->cascadeOnDelete();
            $table->string('platform', 32)->default('tiktok');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->timestamp('collected_at');
            $table->timestamps();

            $table->index(['generated_clip_id', 'collected_at'], 'clip_stats_clip_collected_index');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_stats');
    }
};
