<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            // B-roll moments picked during analysis [{time (source seconds), query}];
            // null = clip analysed before this existed (asked for at render time)
            $table->json('broll_json')->nullable()->after('subtitle_json');
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropColumn('broll_json');
        });
    }
};
