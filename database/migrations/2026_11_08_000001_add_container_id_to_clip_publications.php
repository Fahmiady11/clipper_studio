<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_publications', function (Blueprint $table) {
            // Instagram / Threads: the media container, published once Meta has fetched the video
            $table->string('container_id')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('clip_publications', function (Blueprint $table) {
            $table->dropColumn('container_id');
        });
    }
};
