<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                 // channel | keyword
            $table->string('value');                    // channel URL/@handle or search keyword
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('interval_hours')->default(6);
            $table->unsignedTinyInteger('max_per_run')->default(1);
            $table->unsignedInteger('min_video_seconds')->default(300);
            $table->unsignedInteger('max_video_seconds')->default(7200);
            $table->json('settings');                   // project settings snapshot (layout, subtitle, hook, music…)
            $table->timestamp('last_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('discovered_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('video_id', 32);
            $table->string('title')->nullable();
            $table->string('channel')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->uuid('clip_project_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'video_id']); // never process the same video twice
        });

        Schema::table('clip_projects', function (Blueprint $table) {
            $table->foreignId('video_source_id')->nullable()->after('user_id');
        });

        Schema::table('generated_clips', function (Blueprint $table) {
            $table->string('review_status', 16)->nullable()->after('status'); // pending | approved | rejected
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', fn (Blueprint $t) => $t->dropColumn('review_status'));
        Schema::table('clip_projects', fn (Blueprint $t) => $t->dropColumn('video_source_id'));
        Schema::dropIfExists('discovered_videos');
        Schema::dropIfExists('video_sources');
    }
};
