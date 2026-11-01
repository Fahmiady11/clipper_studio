<?php

namespace App\Http\Controllers;

use App\Exceptions\CannotPost;
use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Models\GeneratedClip;
use App\Models\ScheduledPost;
use App\Services\Autopost;
use App\Services\ConnectedAccounts;
use App\Services\MetaPublishing;
use App\Services\PostOptions;
use App\Services\ScheduledPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Studio's publish panel: one clip to several platforms at once, now or at a
 * time. Every send is a calendar post (ScheduledPost) on the platform's
 * default account, so it goes through the same options, checks and upload
 * jobs as the calendar and shows up there. "Now" is handed over right away.
 */
class PublishController extends Controller
{
    use EnsuresQueueWorker;

    public function __construct(
        private ConnectedAccounts $connected,
        private PostOptions $posts,
        private Autopost $autopost,
    ) {}

    /** Which platforms can take this clip, on which account, and where earlier sends are. */
    public function show(string $clipId): JsonResponse
    {
        return response()->json($this->panel($this->ownedClip($clipId)));
    }

    public function store(Request $request, string $clipId, ScheduledPublisher $publisher): JsonResponse
    {
        $data = $request->validate([
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['distinct', 'in:'.implode(',', ConnectedAccounts::PLATFORMS)],
            'scheduled_at' => ['nullable', 'date'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'hashtags' => ['sometimes', 'array', 'max:60'],
            'hashtags.*' => ['string', 'max:100'],
            'youtube_title' => ['nullable', 'string', 'max:200'],
            'youtube_privacy' => ['nullable', 'in:public,unlisted,private'],
            'tiktok_mode' => ['nullable', 'in:inbox,direct'],
            'tiktok_allow_comment' => ['sometimes', 'boolean'],
            'tiktok_allow_duet' => ['sometimes', 'boolean'],
            'tiktok_allow_stitch' => ['sometimes', 'boolean'],
            'tiktok_branded' => ['sometimes', 'boolean'],
        ], ['platforms.required' => 'Centang minimal satu platform.']);
        $clip = $this->ownedClip($clipId);
        abort_if($clip->status !== 'done' || ! $clip->output_path, 422, 'Clip belum selesai dirender.');
        if (! empty($data['tiktok_branded']) && in_array('tiktok', $data['platforms'], true)) {
            // TikTok rejects branded content from an unaudited app (only private posts are allowed)
            throw ValidationException::withMessages(['platforms.tiktok' => 'Konten branded/bisnis belum tersedia, app TikTok masih menunggu audit konten.']);
        }
        $when = isset($data['scheduled_at']) ? $this->posts->scheduledTime($data['scheduled_at']) : null;

        // Check every platform first: nothing is sent unless all of them can go
        $planned = $errors = [];
        foreach ($data['platforms'] as $platform) {
            try {
                $planned[$platform] = $this->plan($platform, $clip, $data);
            } catch (ValidationException $e) {
                $errors["platforms.{$platform}"] = [collect($e->errors())->flatten()->first()];
            } catch (CannotPost $e) {
                $errors["platforms.{$platform}"] = [$e->getMessage()];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($planned as $platform => [$accountId, $options]) {
            $post = ScheduledPost::create([
                'user_id' => Auth::id(),
                'generated_clip_id' => $clip->id,
                'platform' => $platform,
                'account_id' => $accountId,
                'scheduled_at' => $when ?? now(),
                // "Now" skips the scheduler tick: claimed here and handed over below
                'status' => $when ? ScheduledPost::STATUS_SCHEDULED : ScheduledPost::STATUS_PUBLISHING,
                'options' => $options + ['studio' => true],
            ]);
            if (! $when) {
                $publisher->handOff($post->load('clip.clipProject'));
            }
        }
        if (! $when) {
            $this->ensureQueueWorkerRunning();
        }
        // Remembered for the next clip; the TikTok mode only when TikTok was part of this send
        $request->user()->forceFill(['publish_platforms' => array_values($data['platforms'])] + (in_array('tiktok', $data['platforms'], true)
            ? ['publish_tiktok_mode' => $data['tiktok_mode'] ?? 'inbox']
            : []))->save();

        return response()->json($this->panel($clip->fresh(['clipProject', 'publications'])));
    }

    /**
     * The default account and the post options for one platform, built from
     * the shared caption the way autopilot builds them.
     *
     * @return array{0: int, 1: array}
     */
    private function plan(string $platform, GeneratedClip $clip, array $data): array
    {
        $account = $this->connected->default(Auth::id(), $platform) ?? throw new CannotPost(
            $this->connected->needsDefault(Auth::id(), $platform)
                ? ConnectedAccounts::needsDefaultMessage($platform)
                : 'Belum ada akun '.ConnectedAccounts::NAMES[$platform].' yang terhubung.'
        );
        $busy = ScheduledPost::where('generated_clip_id', $clip->id)->where('platform', $platform)
            ->whereIn('status', [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_PUBLISHING])->exists();
        if ($busy) {
            throw new CannotPost('Clip ini sudah dijadwalkan ke '.ConnectedAccounts::NAMES[$platform].'. Ubah atau batalkan jadwalnya di kalender.');
        }

        $caption = trim((string) ($data['caption'] ?? ''));
        $hashtags = array_values(array_filter(array_map(fn ($h) => '#'.ltrim(trim($h), '#'), $data['hashtags'] ?? []), fn ($h) => $h !== '#'));
        // YouTube, Facebook, Instagram and Threads cut the caption to their limits like autopilot does
        $auto = $platform === 'tiktok' ? [] : $this->autopost->platform($platform)->options($clip, $caption, $hashtags);
        $fields = match ($platform) {
            'youtube' => [
                'title' => trim((string) ($data['youtube_title'] ?? '')) ?: $auto['title'],
                'description' => $auto['description'],
                'tags' => $auto['tags'],
                'privacy' => $data['youtube_privacy'] ?? 'public',
            ],
            'facebook', 'instagram', 'threads' => ['description' => $auto[MetaPublishing::textOption($platform)]],
            default => [
                'mode' => $data['tiktok_mode'] ?? 'inbox',
                // A draft carries no caption; a direct post gets the caption and hashtags
                'title' => mb_substr(trim($caption."\n\n".implode(' ', $hashtags)), 0, 2200),
                'allow_comment' => $data['tiktok_allow_comment'] ?? false,
                'allow_duet' => $data['tiktok_allow_duet'] ?? false,
                'allow_stitch' => $data['tiktok_allow_stitch'] ?? false,
            ],
        };
        $options = $this->posts->build($platform, $fields, $clip);

        return [$this->posts->ownedAccountId(Auth::id(), $platform, $account->id, $options['mode'] ?? null), $options];
    }

    private function panel(GeneratedClip $clip): array
    {
        $userId = Auth::id();
        $posts = ScheduledPost::where('generated_clip_id', $clip->id)->latest('id')->get()->unique('platform')->keyBy('platform');

        return [
            'remembered' => Auth::user()->publish_platforms ?? [],
            'tiktok_mode' => Auth::user()->publish_tiktok_mode ?? 'inbox',
            'platforms' => collect(ConnectedAccounts::PLATFORMS)->map(function (string $platform) use ($userId, $clip, $posts) {
                $account = $this->connected->default($userId, $platform);

                return [
                    'id' => $platform,
                    'name' => ConnectedAccounts::NAMES[$platform],
                    'configured' => $this->connected->isConfigured($platform),
                    'account' => $account ? ['id' => $account->id, 'name' => $this->connected->name($account)] : null,
                    'needs_default' => ! $account && $this->connected->needsDefault($userId, $platform),
                    'settings_url' => '/settings/accounts#'.$platform,
                    // Why this clip can't go there (too long, no public URL), checked before sending
                    'refusal' => $account ? $this->posts->refusal($platform, $clip) : null,
                    'state' => $this->state($clip, $platform, $posts[$platform] ?? null),
                ];
            })->values(),
        ];
    }

    /** The latest send to one platform: its calendar post, then what the platform reported. */
    private function state(GeneratedClip $clip, string $platform, ?ScheduledPost $post): ?array
    {
        $handedOff = ! $post || $post->status === ScheduledPost::STATUS_QUEUED;
        $delivery = $handedOff ? $clip->delivery($platform) : ['status' => null, 'error' => null, 'url' => null, 'privacy' => null, 'note' => null];
        // Sent from Studio before the panel existed: no post, only the clip's own status
        if (! $post && ! $delivery['status']) {
            return null;
        }
        $error = $post?->error ?? $delivery['error'];

        return [
            'post_id' => $post?->id,
            'status' => $post?->status ?? ScheduledPost::STATUS_QUEUED,
            'scheduled_at' => $post?->scheduled_at->toISOString(),
            'delivery' => $delivery['status'],
            'error' => $error,
            'reconnect_url' => ScheduledPost::reconnectUrl($platform, $error),
            'url' => $delivery['url'],
            'privacy' => $delivery['privacy'],
            'note' => $delivery['note'],
        ];
    }

    private function ownedClip(string $clipId): GeneratedClip
    {
        $clip = GeneratedClip::with('clipProject', 'publications')->findOrFail($clipId);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        return $clip;
    }
}
