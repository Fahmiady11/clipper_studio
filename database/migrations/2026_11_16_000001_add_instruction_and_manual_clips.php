<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            // What the user asked the AI to look for, e.g. "bagian tentang modal usaha"
            $table->string('instruction', 300)->nullable();
        });

        Schema::table('generated_clips', function (Blueprint $table) {
            // Cut by the user from the transcript instead of picked by the AI
            $table->boolean('manual')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn('instruction');
        });
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropColumn('manual');
        });
    }
};
