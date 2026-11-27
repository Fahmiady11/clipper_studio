<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Autopilot clips refused for quota before quota_blocked_at existed stayed
 * 'pending' with the quota message and no reservation, so nothing ever
 * rendered them. Mark them waiting, in the order they were made, so
 * clipper:resume-quota picks them up.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('generated_clips')
            ->where('status', 'pending')
            ->where('review_status', 'pending')
            ->whereNull('cancelled_at')
            ->whereNull('quota_blocked_at')
            ->where('error_msg', 'like', 'Kuota render bulan ini habis%')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('render_usages')
                // render_usages.generated_clip_id is a string, generated_clips.id a uuid on Postgres
                ->whereRaw('render_usages.generated_clip_id = CAST(generated_clips.id AS TEXT)')
                ->where('render_usages.status', 'reserved'))
            ->update(['quota_blocked_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Nothing to undo: the column's own migration drops it
    }
};
