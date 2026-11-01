<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A hook variant is a full clip row (same cut, other hook), so render, quota,
        // upload, schedule and stats all work on it unchanged
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->uuid('variant_of_id')->nullable()->after('clip_project_id');
            $table->foreign('variant_of_id')->references('id')->on('generated_clips')->cascadeOnDelete();
            $table->string('hook_angle', 20)->nullable()->after('hook_text_override'); // question | bold_claim | curiosity_gap
        });

        Schema::create('hook_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('generated_clip_id')->unique(); // the original clip; one test per clip
            $table->foreign('generated_clip_id')->references('id')->on('generated_clips')->cascadeOnDelete();
            $table->unsignedTinyInteger('variant_count');
            $table->string('status', 16)->default('draft'); // draft | testing | decided | inconclusive
            $table->unsignedBigInteger('account_id')->nullable(); // TikTok account the variants were posted to
            $table->unsignedSmallInteger('gap_hours')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_by')->nullable(); // judged with whatever data exists by then
            $table->timestamp('decided_at')->nullable();
            $table->uuid('winner_clip_id')->nullable();
            $table->foreign('winner_clip_id')->references('id')->on('generated_clips')->nullOnDelete();
            $table->string('winner_angle', 20)->nullable();
            $table->json('results')->nullable(); // per variant: views and engagement at the comparison age
            $table->string('note')->nullable(); // why no winner was picked
            $table->timestamps();

            $table->index(['status', 'ends_by']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hook_tests');
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropForeign(['variant_of_id']);
            $table->dropColumn(['variant_of_id', 'hook_angle']);
        });
    }
};
