<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            // Set while a render waits for quota (status stays 'pending'); RenderQuota::resumeWaiting starts them oldest first
            $table->timestamp('quota_blocked_at')->nullable()->after('cancelled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropIndex(['quota_blocked_at']);
            $table->dropColumn('quota_blocked_at');
        });
    }
};
