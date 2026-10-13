<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_settings', function (Blueprint $table) {
            // Pop emoji in over keywords. Off for existing projects so a re-render
            // looks like before; new projects get it on via ClipSettings::DEFAULTS.
            $table->boolean('emoji')->default(false)->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_settings', function (Blueprint $table) {
            $table->dropColumn('emoji');
        });
    }
};
