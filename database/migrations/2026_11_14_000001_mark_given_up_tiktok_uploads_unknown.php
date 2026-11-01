<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first TikTok poller marked uploads it gave up on as 'failed'; they are
 * 'unknown' now (the draft may be in the inbox). Only rows with its exact
 * give-up messages change.
 */
return new class extends Migration
{
    private const OLD = [
        'TikTok belum memberi status akhir setelah 24 jam. Cek inbox TikTok di aplikasi; kalau draftnya tidak ada, kirim ulang.' => 'TikTok tidak memberi status akhir dalam 24 jam.',
        'Akun TikTok sudah diputus, status tidak bisa dicek lagi. Cek inbox TikTok di aplikasi.' => 'Akun TikTok sudah diputus, statusnya tidak bisa dicek lagi.',
    ];

    public function up(): void
    {
        foreach (self::OLD as $old => $new) {
            DB::table('generated_clips')->where('tiktok_status', 'failed')->where('tiktok_error', $old)
                ->update(['tiktok_status' => 'unknown', 'tiktok_error' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::OLD as $old => $new) {
            DB::table('generated_clips')->where('tiktok_status', 'unknown')->where('tiktok_error', $new)
                ->update(['tiktok_status' => 'failed', 'tiktok_error' => $old]);
        }
    }
};
