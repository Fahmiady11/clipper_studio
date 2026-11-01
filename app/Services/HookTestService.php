<?php

namespace App\Services;

use App\Exceptions\QuotaExceeded;
use App\Jobs\RenderClipJob;
use App\Models\ClipStat;
use App\Models\GeneratedClip;
use App\Models\HookTest;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Support\HookText;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hook A/B tests. Every variant is a clip row with the same cut and another hook, so it goes
 * through the normal render quota, upload, schedule and stats code. The variants are posted
 * to ONE TikTok account at the same time of day on consecutive days (shuffled order, so the
 * first slot is not always the same angle) and each is judged by its views at the same age.
 */
class HookTestService
{
    public const MIN_VARIANTS = 2;

    public const MAX_VARIANTS = 3;

    public function __construct(
        private GeminiService $gemini,
        private RenderQuota $quota,
        private ClipPerformanceSummary $performance,
    ) {}

    /**
     * Write $count hooks and queue a render for each, charging the render quota per variant.
     *
     * @throws RuntimeException nothing was created (no hook enabled, or the AI could not write distinct hooks)
     * @throws QuotaExceeded nothing was created
     */
    public function createVariants(User $user, GeneratedClip $clip, int $count): HookTest
    {
        $project = $clip->clipProject;
        if (! $project->hookSetting?->enabled) {
            throw new RuntimeException('Hook di project ini dimatikan, jadi tidak ada yang bisa diuji. Aktifkan hook dulu.');
        }

        $hooks = $this->gemini->hookVariants(
            (string) $clip->topic,
            $this->clipText($clip),
            array_slice($this->performance->rankedHookAngles($user->id), 0, $count),
            $project->transcript?->language ?? 'id',
            $this->performance->hookInsight($user->id),
            HookText::plain((string) ($clip->hook_text_override ?? $clip->hook_text ?? '')) ?: null,
        );
        if (count($hooks) < self::MIN_VARIANTS) {
            throw new RuntimeException('AI belum berhasil membuat hook yang cukup berbeda. Coba lagi.');
        }

        [$test, $variants] = DB::transaction(function () use ($user, $clip, $hooks) {
            $test = HookTest::create([
                'user_id' => $user->id,
                'generated_clip_id' => $clip->id,
                'variant_count' => count($hooks),
                'status' => HookTest::STATUS_DRAFT,
            ]);
            $variants = array_map(fn (array $hook) => $this->makeVariant($clip, $hook), $hooks);

            return [$test, $variants];
        });

        try {
            foreach ($variants as $variant) {
                $this->quota->reserve($user, $variant); // each variant is a render of its own
            }
        } catch (QuotaExceeded $e) {
            foreach ($variants as $variant) {
                $this->quota->release($variant->id);
            }
            $test->delete();
            GeneratedClip::whereIn('id', array_map(fn ($v) => $v->id, $variants))->delete();

            throw $e;
        }

        foreach ($variants as $variant) {
            $variant->update(['status' => 'processing']);
            RenderClipJob::dispatch($variant->id);
        }

        return $test;
    }

    /**
     * Post the finished variants to one TikTok account, one per $gapHours, starting at $start.
     * Returns the posts in publishing order.
     *
     * @return Collection<int, ScheduledPost>
     */
    public function schedule(HookTest $test, TiktokAccount $account, CarbonInterface $start, int $gapHours): Collection
    {
        $variants = $test->clip->variants;
        if ($test->status !== HookTest::STATUS_DRAFT) {
            throw new RuntimeException('Tes ini sudah dijadwalkan.');
        }
        if ($variants->contains(fn (GeneratedClip $v) => $v->status !== 'done' || ! $v->output_path || ! Storage::exists($v->output_path))) {
            throw new RuntimeException('Semua varian harus selesai dirender dulu.');
        }
        foreach (['video.publish', 'video.list'] as $scope) { // posting, and reading the views back
            if (! $account->hasScope($scope)) {
                throw new RuntimeException(TiktokAccount::scopeMessage($scope));
            }
        }

        $posts = DB::transaction(function () use ($test, $variants, $account, $start, $gapHours) {
            $window = (int) config('services.clipper.hook_test.window_hours');
            $posts = $variants->shuffle()->values()->map(fn (GeneratedClip $v, int $i) => ScheduledPost::create([
                'user_id' => $test->user_id,
                'generated_clip_id' => $v->id,
                'platform' => 'tiktok',
                'account_id' => $account->id,
                'scheduled_at' => $start->copy()->addHours($i * $gapHours),
                'status' => ScheduledPost::STATUS_SCHEDULED,
                'options' => [
                    'mode' => 'direct',
                    'title' => mb_substr(trim($v->caption.' '.implode(' ', $v->hashtags_json ?? [])), 0, 2200),
                    'allow_comment' => false,
                    'allow_duet' => false,
                    'allow_stitch' => false,
                ],
            ]));

            $test->update([
                'status' => HookTest::STATUS_TESTING,
                'account_id' => $account->id,
                'gap_hours' => $gapHours,
                'started_at' => $posts->first()->scheduled_at,
                'ends_by' => $posts->last()->scheduled_at->copy()->addHours($window + (int) config('services.clipper.hook_test.grace_hours')),
            ]);

            return $posts;
        });

        return $posts;
    }

    /** Remove a test that is not running, with its variant clips and files. */
    public function discard(HookTest $test): void
    {
        foreach ($test->clip->variants as $variant) {
            if ($variant->output_path) {
                Storage::delete($variant->output_path);
            }
        }
        $test->clip->variants()->delete(); // scheduled posts and stats go with them
        $test->delete();
    }

    /** @return int how many tests got a verdict */
    public function judgeDue(): int
    {
        $decided = 0;
        HookTest::with('clip.variants')->where('status', HookTest::STATUS_TESTING)->each(function (HookTest $test) use (&$decided) {
            try {
                $decided += $this->judge($test) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::channel('clipper_jobs')->warning('Hook test judging failed', ['test_id' => $test->id, 'error' => $e->getMessage()]);
            }
        });

        return $decided;
    }

    /**
     * Compare the variants once each has reached the window age (or the grace period ran out).
     * Views decide; when views are within the margin, engagement rate breaks the tie; else no winner.
     *
     * @return bool whether a verdict was recorded
     */
    public function judge(HookTest $test): bool
    {
        $cfg = config('services.clipper.hook_test');
        $window = (int) $cfg['window_hours'];
        $variants = $test->clip->variants;
        $posts = ScheduledPost::whereIn('generated_clip_id', $variants->pluck('id'))->where('platform', 'tiktok')->get()->keyBy('generated_clip_id');

        $cutoffs = $variants->mapWithKeys(function (GeneratedClip $v) use ($posts, $window) {
            $post = $posts->get($v->id);

            return [$v->id => $post ? ($post->handed_off_at ?? $post->scheduled_at)->copy()->addHours($window) : null];
        });
        $pastEnd = $test->ends_by && $test->ends_by->lte(now());
        if (! $pastEnd && $cutoffs->contains(fn ($c) => $c === null || $c->gt(now()))) {
            return false;
        }

        $results = $variants->map(function (GeneratedClip $v) use ($cutoffs, $window) {
            $cutoff = $cutoffs->get($v->id);
            $snap = $cutoff ? ClipStat::where('generated_clip_id', $v->id)->where('platform', 'tiktok')
                ->where('collected_at', '<=', $cutoff)
                ->where('collected_at', '>=', $cutoff->copy()->subHours(intdiv($window, 2))) // a snapshot of a day-old post says nothing about two days
                ->orderByDesc('collected_at')->orderByDesc('id')->first() : null;
            $interactions = $snap ? $snap->likes + $snap->comments + $snap->shares : 0;

            return [
                'clip_id' => $v->id,
                'angle' => $v->hook_angle,
                'hook_text' => $v->hook_text_override,
                'views' => $snap?->views,
                'interactions' => $snap ? $interactions : null,
                'rate' => $snap && $snap->views > 0 ? round($interactions / $snap->views, 4) : null,
                'snapshot_at' => $snap?->collected_at->toIso8601String(),
            ];
        })->values();

        $eligible = $results->filter(fn (array $r) => $r['views'] !== null)->sortByDesc('views')->values();
        [$winner, $note] = $this->pick($eligible, (int) $cfg['min_views'], (float) $cfg['margin']);

        $test->update([
            'status' => $winner ? HookTest::STATUS_DECIDED : HookTest::STATUS_INCONCLUSIVE,
            'winner_clip_id' => $winner['clip_id'] ?? null,
            'winner_angle' => $winner['angle'] ?? null,
            'decided_at' => now(),
            'results' => $results->all(),
            'note' => $note,
        ]);
        Log::channel('clipper_jobs')->info('Hook test judged', ['test_id' => $test->id, 'status' => $test->status, 'winner' => $winner['clip_id'] ?? null]);

        return true;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ranked  views, highest first
     * @return array{0: ?array<string, mixed>, 1: ?string} winner and, when none, why
     */
    private function pick(Collection $ranked, int $minViews, float $margin): array
    {
        if ($ranked->count() < self::MIN_VARIANTS) {
            return [null, 'Data views belum cukup: kurang dari dua varian punya statistik TikTok. Cek izin video.list dan pastikan postingnya sudah tayang.'];
        }
        $top = $ranked[0];
        if ($top['views'] < $minViews) {
            return [null, "Views terlalu sedikit untuk dibandingkan (tertinggi {$top['views']}). Posting privat atau akun baru sering begini."];
        }
        if ($top['views'] >= $ranked[1]['views'] * $margin) {
            return [$top, null];
        }

        // Views are too close to call: engagement among the near-tied ones
        $tied = $ranked->filter(fn (array $r) => $top['views'] <= $r['views'] * $margin)->sortByDesc('rate')->values();
        if ($tied->count() >= 2 && ($tied[0]['rate'] ?? 0) > 0 && $tied[0]['rate'] >= ($tied[1]['rate'] ?? 0) * $margin) {
            return [$tied[0], null];
        }

        return [null, 'Selisih views dan interaksi terlalu tipis untuk menyebut pemenang.'];
    }

    /** @return array<string, mixed> */
    public function present(HookTest $test): array
    {
        $variants = $test->clip->variants()->get();
        $totals = $this->performance->totalsForClips($variants->pluck('id'));
        $posts = ScheduledPost::whereIn('generated_clip_id', $variants->pluck('id'))->where('platform', 'tiktok')->get()->keyBy('generated_clip_id');

        return [
            'id' => $test->id,
            'clip_id' => $test->generated_clip_id,
            'status' => $test->status,
            'account_id' => $test->account_id,
            'gap_hours' => $test->gap_hours,
            'window_hours' => (int) config('services.clipper.hook_test.window_hours'),
            'started_at' => $test->started_at?->toIso8601String(),
            'ends_by' => $test->ends_by?->toIso8601String(),
            'decided_at' => $test->decided_at?->toIso8601String(),
            'winner_clip_id' => $test->winner_clip_id,
            'winner_angle' => $test->winner_angle,
            'note' => $test->note,
            'variants' => $variants->map(fn (GeneratedClip $v) => [
                'id' => $v->id,
                'hook_text' => $v->hook_text_override,
                'angle' => $v->hook_angle,
                'angle_label' => $v->hook_angle ? HookTest::angleLabel($v->hook_angle) : null,
                'status' => $v->status,
                'error_msg' => $v->status === 'failed' ? $v->error_msg : null,
                'scheduled_at' => $posts->get($v->id)?->scheduled_at->toIso8601String(),
                'post_status' => $posts->get($v->id)?->status,
                'delivery' => $v->tiktok_status,
                'views' => $totals[$v->id]['views'] ?? null,
                'likes' => $totals[$v->id]['likes'] ?? null,
                'is_winner' => $v->id === $test->winner_clip_id,
                'download_url' => $v->status === 'done' ? url("/api/clips/{$v->id}/download") : null,
            ])->values()->all(),
        ];
    }

    /** @param  array{angle: string, text: string}  $hook */
    private function makeVariant(GeneratedClip $clip, array $hook): GeneratedClip
    {
        // Everything but the hook is copied, so a variant needs no extra AI call (caption, B-roll picks, subtitles, mood)
        return GeneratedClip::create([
            'clip_project_id' => $clip->clip_project_id,
            'variant_of_id' => $clip->id,
            'ranking' => $clip->ranking,
            'start_seconds' => $clip->start_seconds,
            'end_seconds' => $clip->end_seconds,
            'topic' => $clip->topic,
            'reason' => $clip->reason,
            'viral_potential' => $clip->viral_potential,
            'hook_text' => $hook['text'],
            'hook_text_override' => $hook['text'],
            'hook_angle' => $hook['angle'],
            'subtitle_json' => $clip->subtitle_json,
            'caption' => $clip->caption,
            'hashtags_json' => $clip->hashtags_json,
            'broll_json' => $clip->broll_json,
            'music_mood' => $clip->music_mood,
            'status' => 'pending',
        ]);
    }

    /** What is said in the clip, for the hook prompt. */
    private function clipText(GeneratedClip $clip): string
    {
        $segments = $clip->clipProject->transcript?->content ?? $clip->subtitle_json ?? [];

        return collect($segments)
            ->filter(fn ($s) => ($s['end'] ?? 0) > $clip->start_seconds && ($s['start'] ?? 0) < $clip->end_seconds)
            ->map(fn ($s) => trim((string) ($s['text'] ?? '')))
            ->filter()
            ->implode(' ');
    }
}
