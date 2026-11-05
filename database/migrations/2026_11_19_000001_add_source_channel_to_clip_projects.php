<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Channel of the video an autopilot project was cut from, for the "Sumber:" credit in captions
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->string('source_channel', 150)->nullable()->after('video_title');
        });
    }

    public function down(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn('source_channel');
        });
    }
};
