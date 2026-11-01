<?php

namespace App\Services;

use App\Exceptions\QuotaExceeded;
use App\Models\GeneratedClip;
use App\Models\Plan;
use App\Models\RenderUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Monthly render-minute quota: reserve when a render is queued, settle or release when the job ends. */
class RenderQuota
{
    public function period(): string
    {
        return now()->format('Y-m');
    }

    public function plan(User $user): Plan
    {
        return $user->plan ?? Plan::free();
    }

    public function usedSeconds(User $user): int
    {
        return (int) RenderUsage::where('user_id', $user->id)->where('period', $this->period())->sum('seconds');
    }

    public function limitSeconds(User $user): int
    {
        return $this->plan($user)->render_minutes * 60;
    }

    public function remainingSeconds(User $user): int
    {
        return max(0, $this->limitSeconds($user) - $this->usedSeconds($user));
    }

    /** @throws QuotaExceeded when the clip would not fit in what is left this month */
    public function reserve(User $user, GeneratedClip $clip): RenderUsage
    {
        $seconds = max(1, (int) ceil((float) $clip->end_seconds - (float) $clip->start_seconds));

        return DB::transaction(function () use ($user, $clip, $seconds) {
            User::whereKey($user->id)->lockForUpdate()->first(); // serialise concurrent reservations

            RenderUsage::where('generated_clip_id', $clip->id)->where('status', 'reserved')->delete();

            if (! $user->is_admin && $seconds > $this->remainingSeconds($user)) {
                throw new QuotaExceeded($this->message($user));
            }

            return RenderUsage::create([
                'user_id' => $user->id,
                'generated_clip_id' => $clip->id,
                'period' => $this->period(),
                'seconds' => $seconds,
            ]);
        });
    }

    /** Swap the estimate for the real output length and log what the render cost. */
    public function settle(string $clipId, int $outputSeconds, float $cpuSeconds, float $aiCostUsd = 0.0): void
    {
        $usage = RenderUsage::where('generated_clip_id', $clipId)->where('status', 'reserved')->latest('id')->first();
        if (! $usage) {
            return;
        }

        $cost = $cpuSeconds / 3600 * config('billing.cpu_usd_per_hour') + $aiCostUsd;
        $usage->update([
            'status' => 'charged',
            'seconds' => max(1, $outputSeconds),
            'cpu_seconds' => (int) round($cpuSeconds),
            'cost_usd' => round($cost, 6),
        ]);

        Log::channel('clipper_jobs')->info('Render cost', [
            'clip_id' => $clipId,
            'user_id' => $usage->user_id,
            'output_seconds' => $usage->seconds,
            'cpu_seconds' => $usage->cpu_seconds,
            'cost_usd' => (float) $usage->cost_usd,
        ]);
    }

    /** A render that failed, was cancelled or never ran costs the user nothing. */
    public function release(string $clipId): void
    {
        RenderUsage::where('generated_clip_id', $clipId)->where('status', 'reserved')->delete();
    }

    public function message(User $user): string
    {
        $used = (int) floor($this->usedSeconds($user) / 60);
        $limit = $this->plan($user)->render_minutes;

        return "Kuota render bulan ini habis ({$used} dari {$limit} menit terpakai). "
            .'Upgrade paket di halaman Paket & kuota, atau tunggu reset tanggal 1.';
    }
}
