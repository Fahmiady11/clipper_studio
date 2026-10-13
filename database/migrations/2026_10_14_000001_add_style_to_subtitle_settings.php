<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_settings', function (Blueprint $table) {
            // karaoke: line with the spoken word coloured; pop: UPPERCASE, outlined,
            // spoken word grows; word: one big word at a time
            $table->string('style', 16)->default('karaoke')->after('background_style');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_settings', function (Blueprint $table) {
            $table->dropColumn('style');
        });
    }
};
