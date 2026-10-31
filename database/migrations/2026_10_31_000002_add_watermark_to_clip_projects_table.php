<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->boolean('watermark')->default(false)->after('broll');
        });
    }

    public function down(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn('watermark');
        });
    }
};
