<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
        });

        Schema::table('generated_clips', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
            // Hook text the user edited for this one clip; beats project/AI text
            $table->text('hook_text_override')->nullable()->after('hook_text');
        });
    }

    public function down(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });

        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'hook_text_override']);
        });
    }
};
