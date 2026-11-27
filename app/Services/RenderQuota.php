<?php

namespace App\Services;

use App\Exceptions\QuotaExceeded;
use App\Jobs\RenderClipJob;
use App\Models\GeneratedClip;
use App\Models\Plan;
use App\Models\RenderUsage;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Monthly render-minute quota: reserve when a render is queued, settle or release when the job ends. */
class RenderQuota
{
    private const RESUME_LOCK_SECONDS = 120;

    private const RESUME_WAIT_SECONDS = 10;

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
    /** Refund a reservation that was never rendered; the freed seconds go to clips waiting for quota. */
    public function release(string $clipId): void
    {
        $userId = RenderUsage::where('generated_clip_id', $clipId)->where('status', 'reserved')->value('user_id');
        if (! RenderUsage::where('generated_clip_id', $clipId)->where('status', 'reserved')->delete()) {
            return;
        }

        if ($user = User::find($userId)) {
            $this->resumeWaiting($user);
        }
    }

    /**
     * A render refused for quota that should still happen later: the clip
     * stays 'pending' and resumeWaiting() queues it once there is room.
     */
    public function block(GeneratedClip $clip, QuotaExceeded $e): void
    {
        $clip->update(['quota_blocked_at' => $clip->quota_blocked_at ?? now(), 'error_msg' => $e->getMessage()]);
    }

    /**
     * Queue the user's clips waiting for quota, oldest first, until one does
     * not fit. The lock keeps a plan change, a release and the schedule from
     * dispatching the same clip twice. Returns how many were queued.
     */
    public function resumeWaiting(User $user): int
    {
        try {
            return Cache::lock('render-quota-resume:'.$user->id, self::RESUME_LOCK_SECONDS)
                ->block(self::RESUME_WAIT_SECONDS, fn () => $this->resumeLocked($user));
        } catch (LockTimeoutException) {
            return 0; // clipper:resume-quota tries again within minutes
        }
    }

    /** resumeWaiting() for every approved user with a clip waiting for quota. */
    public function resumeAll(): int
    {
        $userIds = GeneratedClip::whereNotNull('quota_blocked_at')
            ->join('clip_projects', 'clip_projects.id', '=', 'generated_clips.clip_project_id')
            ->distinct()
            ->pluck('clip_projects.user_id');

        return User::whereIn('id', $userIds)->whereNotNull('approved_at')->get()
            ->sum(fn (User $user) => $this->resumeWaiting($user));
    }

    private function resumeLocked(User $user): int
    {
        $waiting = GeneratedClip::with('clipProject')
            ->whereNotNull('quota_blocked_at')
            ->whereHas('clipProject', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('quota_blocked_at')
            ->orderBy('ranking')
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($waiting as $clip) {
            // Rejected, cancelled, project cancelled, or rendered some other way meanwhile: it no longer waits
            if ($clip->review_status === 'rejected' || $clip->cancelled_at || $clip->clipProject->cancelled_at || $clip->status !== 'pending') {
                $clip->update(['quota_blocked_at' => null]);

                continue;
            }

            try {
                $this->reserve($user, $clip);
            } catch (QuotaExceeded $e) {
                $clip->update(['error_msg' => $e->getMessage()]); // the minutes in the message are current again

                break;
            }

            $clip->update(['quota_blocked_at' => null, 'error_msg' => null]);
            RenderClipJob::dispatch($clip->id);
            $queued++;
        }

        if ($queued) {
            Log::channel('clipper_jobs')->info('Renders resumed after quota wait', ['user_id' => $user->id, 'queued' => $queued]);
        }

        return $queued;
    }

    public function message(User $user): string
    {
        $used = (int) floor($this->usedSeconds($user) / 60);
        $limit = $this->plan($user)->render_minutes;

        return "Kuota render bulan ini habis ({$used} dari {$limit} menit terpakai). "
            .'Upgrade paket di halaman Paket & kuota, atau tunggu reset tanggal 1.';
    }
}
