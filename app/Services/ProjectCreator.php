<?php

namespace App\Services;

use App\Jobs\AnalyzeVideoJob;
use App\Models\BrandKit;
use App\Models\ClipProject;
use App\Models\HookSetting;
use App\Models\SubtitleSetting;
use Illuminate\Validation\ValidationException;

/**
 * Creates a clip project with its subtitle/hook settings and queues analysis.
 * $settings has the StoreProjectRequest shape (layout_type, clip_count,
 * duration_mode, min/max_duration, subtitle{}, hook{}, music{}), so studio
 * requests and autopilot sources share one path.
 */
class ProjectCreator
{
    public function __construct(private YouTubeService $youtube) {}

    public function create(
        int $userId,
        string $youtubeUrl,
        array $settings,
        ?int $videoSourceId = null,
        ?string $sourceFilePath = null,
        ?string $verifiedChannelId = null,
    ): ClipProject {
        // One choke point for every project path (studio, autopilot, retries):
        // a YouTube source must belong to a channel the user proved by OAuth.
        // A directly uploaded file needs no channel check.
        if ($sourceFilePath === null && $verifiedChannelId === null
            && ! $this->youtube->verifiedChannelIdForVideo($userId, $youtubeUrl)) {
            throw ValidationException::withMessages([
                'youtube_url' => 'Video ini bukan dari channel YouTube yang sudah kamu verifikasi. Clipper hanya mengolah video milikmu sendiri. Hubungkan channel di /settings/youtube, lalu tempel link video dari channel itu — atau unggah file videonya langsung.',
            ]);
        }

        $manual = ($settings['duration_mode'] ?? 'auto') === 'manual';
        // Brand kit fills in only what the request leaves out; explicit values win
        $brand = BrandKit::where('user_id', $userId)->first()?->projectDefaults() ?? [];

        $project = ClipProject::create([
            'user_id' => $userId,
            'video_source_id' => $videoSourceId,
            'youtube_url' => $youtubeUrl,
            'source_file_path' => $sourceFilePath,
            'layout_type' => $settings['layout_type'] ?? 'reframe',
            'jump_cut' => $settings['edit']['jump_cut'] ?? true,
            'auto_zoom' => $settings['edit']['zoom'] ?? true,
            'progress_bar' => $settings['edit']['progress'] ?? true,
            'top_title' => $settings['edit']['title'] ?? false,
            'broll' => $settings['edit']['broll'] ?? true,
            'watermark' => $settings['watermark'] ?? $brand['watermark'] ?? false,
            'clip_count' => $settings['clip_count'] ?? 3,
            'duration_mode' => $settings['duration_mode'] ?? 'auto',
            'min_duration' => $manual ? ($settings['min_duration'] ?? null) : null,
            'max_duration' => $manual ? ($settings['max_duration'] ?? null) : null,
            'music_enabled' => $settings['music']['enabled'] ?? false,
            'music_mood' => $settings['music']['mood'] ?? null,
            'music_volume' => $settings['music']['volume'] ?? 25,
            'status' => 'processing',
        ]);

        $sub = $settings['subtitle'] ?? [];
        $sub += array_filter(array_intersect_key($brand, array_flip(['font_family', 'highlight_color', 'text_color'])), fn ($v) => $v !== null);
        SubtitleSetting::create([
            'clip_project_id' => $project->id,
            'enabled' => $sub['enabled'] ?? true,
            'font_family' => $sub['font_family'] ?? 'Montserrat',
            'font_size' => $sub['font_size'] ?? 56,
            'text_color' => $sub['text_color'] ?? '#ffffff',
            'highlight_color' => $sub['highlight_color'] ?? '#facc15',
            'position' => $sub['position'] ?? 'bottom',
            'position_y' => $sub['position_y'] ?? null,
            'background_style' => $sub['background_style'] ?? 'semi',
            'style' => $sub['style'] ?? 'karaoke',
            'emoji' => $sub['emoji'] ?? true,
        ]);

        $hook = $settings['hook'] ?? [];
        $hook += array_filter(array_intersect_key($brand, array_flip(['text_color', 'background_style'])), fn ($v) => $v !== null);
        HookSetting::create([
            'clip_project_id' => $project->id,
            'enabled' => $hook['enabled'] ?? true,
            'hook_text' => $hook['hook_text'] ?? null,
            'is_ai_generated' => $hook['is_ai_generated'] ?? false,
            'duration_seconds' => $hook['duration_seconds'] ?? 3.0,
            'position' => $hook['position'] ?? 'center',
            'text_color' => $hook['text_color'] ?? '#ffffff',
            'background_style' => $hook['background_style'] ?? 'semi',
        ]);

        AnalyzeVideoJob::dispatch($project->id);

        return $project;
    }

    /** Snapshot an existing project's settings, e.g. as an autopilot template. */
    public function settingsFrom(?ClipProject $project): array
    {
        if (! $project) {
            // AI-written hook: a fixed default text would be wrong for arbitrary videos
            return ['hook' => ['enabled' => true, 'is_ai_generated' => true]];
        }

        $project->loadMissing(['subtitleSetting', 'hookSetting']);
        $sub = $project->subtitleSetting;
        $hook = $project->hookSetting;

        return [
            'layout_type' => $project->layout_type,
            'clip_count' => $project->clip_count,
            'duration_mode' => $project->duration_mode,
            'min_duration' => $project->min_duration,
            'max_duration' => $project->max_duration,
            'edit' => ['jump_cut' => $project->jump_cut, 'zoom' => $project->auto_zoom, 'progress' => $project->progress_bar, 'title' => $project->top_title, 'broll' => $project->broll],
            'subtitle' => $sub ? $sub->only(['enabled', 'font_family', 'font_size', 'text_color', 'highlight_color', 'position', 'position_y', 'background_style', 'style', 'emoji']) : [],
            'hook' => $hook ? $hook->only(['enabled', 'hook_text', 'is_ai_generated', 'duration_seconds', 'position', 'text_color', 'background_style']) : [],
            'music' => ['enabled' => $project->music_enabled, 'mood' => $project->music_mood, 'volume' => $project->music_volume],
        ];
    }
}
