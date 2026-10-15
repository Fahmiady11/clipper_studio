<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            // Off for existing projects so a re-render gives the clip it gave before
            $table->boolean('progress_bar')->default(false)->after('auto_zoom');
            $table->boolean('top_title')->default(false)->after('progress_bar');
        });
    }

    public function down(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn(['progress_bar', 'top_title']);
        });
    }
};
