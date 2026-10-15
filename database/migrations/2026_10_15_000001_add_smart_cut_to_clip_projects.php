<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            // Jump cuts (pauses, filler words) and punch-in zoom. Off for existing
            // projects so a re-render gives the clip it gave before.
            $table->boolean('jump_cut')->default(false)->after('layout_type');
            $table->boolean('auto_zoom')->default(false)->after('jump_cut');
        });
    }

    public function down(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn(['jump_cut', 'auto_zoom']);
        });
    }
};
