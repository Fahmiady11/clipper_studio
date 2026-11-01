<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Until now a missing default meant "the oldest account". From here on the
     * default is always stored, so disconnecting it stops autopilot instead of
     * moving posts to another account: store today's effective default.
     */
    public function up(): void
    {
        $sources = [
            'tiktok' => DB::table('tiktok_accounts')->selectRaw('user_id, min(id) as account_id')->groupBy('user_id'),
            'youtube' => DB::table('youtube_accounts')->selectRaw('user_id, min(id) as account_id')->groupBy('user_id'),
        ];
        foreach (['facebook', 'instagram', 'threads'] as $platform) {
            $sources[$platform] = DB::table('meta_targets')->where('platform', $platform)->selectRaw('user_id, min(id) as account_id')->groupBy('user_id');
        }

        foreach ($sources as $platform => $query) {
            foreach ($query->get() as $row) {
                $current = DB::table('platform_settings')->where('user_id', $row->user_id)->where('platform', $platform)->first();
                if (! $current) {
                    DB::table('platform_settings')->insert([
                        'user_id' => $row->user_id, 'platform' => $platform, 'account_id' => $row->account_id,
                        'enabled' => false, 'max_per_day' => 3, 'slot_times' => json_encode(['09:00', '15:00', '20:00']),
                        'timezone' => 'Asia/Jakarta', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif (! $current->account_id) {
                    DB::table('platform_settings')->where('id', $current->id)->update(['account_id' => $row->account_id, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Pinned defaults are the same accounts the old fallback picked; nothing to undo
    }
};
