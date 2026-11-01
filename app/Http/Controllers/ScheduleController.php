<?php

namespace App\Http\Controllers;

use App\Exceptions\CannotPost;
use App\Models\GeneratedClip;
use App\Models\MetaTarget;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\YoutubeAccount;
use App\Services\PostOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function __construct(private PostOptions $posts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();
        abort_if($from->diffInDays($to) > 62, 422, 'Rentang maksimal 62 hari.');

        $posts = ScheduledPost::with('clip.clipProject', 'clip.publications')
            ->where('user_id', Auth::id())
            ->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<', $to)
            ->orderBy('scheduled_at')
            ->get();

        $labels = collect(ScheduledPost::PLATFORMS)->mapWithKeys(fn ($platform) => [$platform => $this->accountLabels($platform)]);

        return response()->json([
            'posts' => $posts->map(fn (ScheduledPost $p) => $this->present($p, $labels[$p->platform]))->values(),
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
        ] + PostOptions::rules());
        $when = $this->posts->scheduledTime($data['scheduled_at']);

        $clip = GeneratedClip::with('clipProject')->findOrFail($data['clip_id']);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);
        abort_if($clip->status !== 'done' || ! $clip->output_path, 422, 'Clip belum selesai dirender.');

        try {
            $options = $this->posts->build($data['platform'], $data, $clip);
            $accountId = $this->posts->ownedAccountId(Auth::id(), $data['platform'], (int) $data['account_id'], $options['mode'] ?? null);
        } catch (CannotPost $e) {
            abort(422, $e->getMessage());
        }

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

        return response()->json(['post' => $this->present($post->load('clip.clipProject', 'clip.publications'), $this->accountLabels($post->platform))], 201);
    }

    /** Move a waiting post, or put a failed one back in line. */
    public function update(Request $request, int $postId): JsonResponse
    {
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        $post = $this->ownedPost($postId);
        abort_unless(in_array($post->status, [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_FAILED], true), 422, 'Jadwal ini sudah diproses dan tidak bisa diubah.');

        $post->update(['scheduled_at' => $this->posts->scheduledTime($data['scheduled_at']), 'status' => ScheduledPost::STATUS_SCHEDULED, 'error' => null]);

        return response()->json(['post' => $this->present($post->load('clip.clipProject', 'clip.publications'), $this->accountLabels($post->platform))]);
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

    private function accountLabels(string $platform)
    {
        return match ($platform) {
            'tiktok' => TiktokAccount::where('user_id', Auth::id())->pluck('display_name', 'id'),
            'youtube' => YoutubeAccount::where('user_id', Auth::id())->pluck('title', 'id'),
            default => MetaTarget::ownedBy(Auth::id(), $platform)->pluck('name', 'id'),
        };
    }

    private function present(ScheduledPost $p, $accountLabels): array
    {
        $clip = $p->clip;
        // Before hand-off the clip's status may belong to an earlier upload
        $delivery = $p->status === ScheduledPost::STATUS_QUEUED && $clip
            ? $clip->delivery($p->platform)
            : ['status' => null, 'error' => null, 'url' => null, 'privacy' => null, 'note' => null];
        $error = $p->error ?? $delivery['error'];

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
            'error' => $error,
            'reconnect_url' => ScheduledPost::reconnectUrl($p->platform, $error),
            // What the platform reported after hand-off: queued, uploading, processing, inbox, unknown, published, failed
            'delivery' => $delivery['status'],
            // YouTube only: private while the API project is unaudited
            'privacy' => $delivery['privacy'],
            'url' => $delivery['url'],
            'note' => $delivery['note'],
            'options' => $p->options,
        ];
    }
}
