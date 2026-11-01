<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per user and platform: the default account (account_id) used by Studio, the
        // calendar and autopilot, plus the autopost switch and slots. Settings → Akun terhubung.
        Schema::rename('autopost_settings', 'platform_settings');

        // TikTok joins: until now approving sent to the first TikTok account picked on the
        // autopilot page. Keep that for everyone who has one: on, first account as default.
        $firstAccounts = DB::table('tiktok_accounts')->selectRaw('user_id, min(id) as account_id')->groupBy('user_id')->get();
        foreach ($firstAccounts as $row) {
            DB::table('platform_settings')->insertOrIgnore([
                'user_id' => $row->user_id,
                'platform' => 'tiktok',
                'account_id' => $row->account_id,
                'enabled' => true,
                'max_per_day' => 3,
                'slot_times' => json_encode(['09:00', '15:00', '20:00']),
                'timezone' => 'Asia/Jakarta',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('platform', 'tiktok')->delete();
        Schema::rename('platform_settings', 'autopost_settings');
    }
};
