<?php

namespace App\Http\Controllers;

use App\Models\GeneratedClip;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\YoutubeAccount;
use App\Services\FFmpegService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScheduleController extends Controller
{
    // The scheduler ticks once a minute, so anything sooner than this would be late by design
    private const MIN_LEAD_MINUTES = 2;

    private const MAX_LEAD_DAYS = 365;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();
        abort_if($from->diffInDays($to) > 62, 422, 'Rentang maksimal 62 hari.');

        $posts = ScheduledPost::with('clip.clipProject')
            ->where('user_id', Auth::id())
            ->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<', $to)
            ->orderBy('scheduled_at')
            ->get();

        $tiktok = TiktokAccount::where('user_id', Auth::id())->pluck('display_name', 'id');
        $youtube = YoutubeAccount::where('user_id', Auth::id())->pluck('title', 'id');

        return response()->json([
            'posts' => $posts->map(fn (ScheduledPost $p) => $this->present($p, $p->platform === 'tiktok' ? $tiktok : $youtube))->values(),
        ]);
    }

    /** Rendered clips that can still be published. */
    public function clips(): JsonResponse
    {
        $clips = GeneratedClip::with('clipProject:id,video_title,thumbnail_url,user_id')
            ->where('status', 'done')
            ->whereNull('variant_of_id') // hook test variants are posted from their test
            ->whereNotNull('output_path')
            ->where(fn ($q) => $q->whereNull('review_status')->orWhere('review_status', '!=', 'rejected'))
            ->whereHas('clipProject', fn ($q) => $q->where('user_id', Auth::id()))
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'clips' => $clips->map(fn (GeneratedClip $c) => [
                'id' => $c->id,
                'topic' => $c->topic,
                'project_title' => $c->clipProject->video_title,
                'thumbnail_url' => $c->clipProject->thumbnail_url,
                'seconds' => round($c->durationSeconds()),
                'caption' => $c->caption,
                'hashtags' => $c->hashtags_json ?? [],
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clip_id' => ['required', 'string'],
            'platform' => ['required', 'in:'.implode(',', ScheduledPost::PLATFORMS)],
            'account_id' => ['required', 'integer'],
            'scheduled_at' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:2200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['nullable', 'in:public,unlisted,private'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
            'mode' => ['nullable', 'in:inbox,direct'],
            'allow_comment' => ['sometimes', 'boolean'],
            'allow_duet' => ['sometimes', 'boolean'],
            'allow_stitch' => ['sometimes', 'boolean'],
        ]);
        $when = $this->validTime($data['scheduled_at']);

        $clip = GeneratedClip::with('clipProject')->findOrFail($data['clip_id']);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);
        abort_if($clip->status !== 'done' || ! $clip->output_path, 422, 'Clip belum selesai dirender.');

        $options = $data['platform'] === 'youtube'
            ? $this->youtubeOptions($data, $clip)
            : $this->tiktokOptions($data);
        $accountId = $this->ownedAccountId($data['platform'], (int) $data['account_id'], $options['mode'] ?? null);

        $taken = ScheduledPost::where('generated_clip_id', $clip->id)
            ->where('platform', $data['platform'])
            ->whereIn('status', [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_PUBLISHING])
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['clip_id' => 'Clip ini sudah dijadwalkan ke platform itu. Ubah atau batalkan jadwal yang ada dulu.']);
        }

        $post = ScheduledPost::create([
            'user_id' => Auth::id(),
            'generated_clip_id' => $clip->id,
            'platform' => $data['platform'],
            'account_id' => $accountId,
            'scheduled_at' => $when,
            'status' => ScheduledPost::STATUS_SCHEDULED,
            'options' => $options,
        ]);

        return response()->json(['post' => $this->present($post->load('clip.clipProject'), $this->accountLabels($post))], 201);
    }

    /** Move a waiting post, or put a failed one back in line. */
    public function update(Request $request, int $postId): JsonResponse
    {
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        $post = $this->ownedPost($postId);
        abort_unless(in_array($post->status, [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_FAILED], true), 422, 'Jadwal ini sudah diproses dan tidak bisa diubah.');

        $post->update(['scheduled_at' => $this->validTime($data['scheduled_at']), 'status' => ScheduledPost::STATUS_SCHEDULED, 'error' => null]);

        return response()->json(['post' => $this->present($post->load('clip.clipProject'), $this->accountLabels($post))]);
    }

    public function destroy(int $postId): JsonResponse
    {
        $post = $this->ownedPost($postId);
        abort_unless(in_array($post->status, [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_FAILED], true), 422, 'Jadwal ini sudah diproses dan tidak bisa dibatalkan.');
        $post->delete();

        return response()->json(['success' => true]);
    }

    private function ownedPost(int $postId): ScheduledPost
    {
        return ScheduledPost::where('user_id', Auth::id())->findOrFail($postId);
    }

    private function validTime(string $value): CarbonImmutable
    {
        $when = CarbonImmutable::parse($value)->utc();
        if ($when->lt(now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            throw ValidationException::withMessages(['scheduled_at' => 'Waktu publish minimal '.self::MIN_LEAD_MINUTES.' menit dari sekarang.']);
        }
        if ($when->gt(now()->addDays(self::MAX_LEAD_DAYS))) {
            throw ValidationException::withMessages(['scheduled_at' => 'Jadwal maksimal '.self::MAX_LEAD_DAYS.' hari ke depan.']);
        }

        return $when;
    }

    private function youtubeOptions(array $data, GeneratedClip $clip): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100 || preg_match('/[<>]/', $title)) {
            throw ValidationException::withMessages(['title' => 'Judul YouTube wajib diisi, maksimal 100 karakter, tanpa < atau >.']);
        }

        // Rendered length: jump cuts make it shorter than end - start
        try {
            $seconds = app(FFmpegService::class)->probeDuration(Storage::path($clip->output_path));
        } catch (Throwable) {
            $seconds = $clip->durationSeconds();
        }
        abort_if($seconds > YouTubeController::MAX_SHORT_SECONDS + 0.5, 422, 'YouTube Shorts maksimal 3 menit, clip ini '.round($seconds).' detik. Pendekkan clip dulu.');

        return [
            'title' => $title,
            'description' => (string) ($data['description'] ?? ''),
            'privacy' => $data['privacy'] ?? 'private',
            'tags' => $data['tags'] ?? [],
        ];
    }

    private function tiktokOptions(array $data): array
    {
        return [
            'mode' => $data['mode'] ?? 'inbox',
            'title' => (string) ($data['title'] ?? ''),
            'allow_comment' => (bool) ($data['allow_comment'] ?? false),
            'allow_duet' => (bool) ($data['allow_duet'] ?? false),
            'allow_stitch' => (bool) ($data['allow_stitch'] ?? false),
        ];
    }

    private function ownedAccountId(string $platform, int $accountId, ?string $mode): int
    {
        if ($platform === 'youtube') {
            return YoutubeAccount::where('user_id', Auth::id())->findOrFail($accountId)->id;
        }

        $account = TiktokAccount::where('user_id', Auth::id())->findOrFail($accountId);
        $scope = $mode === 'direct' ? 'video.publish' : 'video.upload';
        abort_unless($account->hasScope($scope), 422, TiktokAccount::scopeMessage($scope));

        return $account->id;
    }

    private function accountLabels(ScheduledPost $post)
    {
        return $post->platform === 'tiktok'
            ? TiktokAccount::where('user_id', Auth::id())->pluck('display_name', 'id')
            : YoutubeAccount::where('user_id', Auth::id())->pluck('title', 'id');
    }

    private function present(ScheduledPost $p, $accountLabels): array
    {
        $clip = $p->clip;
        $delivery = $p->status === ScheduledPost::STATUS_QUEUED
            ? ($p->platform === 'tiktok' ? $clip?->tiktok_status : $clip?->youtube_status)
            : null;
        $deliveryError = $p->status === ScheduledPost::STATUS_QUEUED
            ? ($p->platform === 'tiktok' ? $clip?->tiktok_error : $clip?->youtube_error)
            : null;

        return [
            'id' => $p->id,
            'clip_id' => $p->generated_clip_id,
            'clip_topic' => $clip?->topic,
            'project_title' => $clip?->clipProject?->video_title,
            'thumbnail_url' => $clip?->clipProject?->thumbnail_url,
            'platform' => $p->platform,
            'account_label' => $accountLabels[$p->account_id] ?? null,
            'scheduled_at' => $p->scheduled_at->toISOString(),
            'status' => $p->status,
            'error' => $p->error ?? $deliveryError,
            // What the platform reported after hand-off: queued, uploading, processing, inbox, published, failed
            'delivery' => $delivery,
            // YouTube only: private while the API project is unaudited
            'privacy' => $p->platform === 'youtube' && $p->status === ScheduledPost::STATUS_QUEUED ? $clip?->youtube_privacy : null,
            'url' => $p->platform === 'youtube' && $clip?->youtube_video_id && $p->status === ScheduledPost::STATUS_QUEUED
                ? 'https://youtube.com/shorts/'.$clip->youtube_video_id
                : null,
            'options' => $p->options,
        ];
    }
}
