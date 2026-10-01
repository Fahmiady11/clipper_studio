<?php

namespace App\Services;

use App\Jobs\AnalyzeVideoJob;
use App\Models\ClipProject;
use App\Models\HookSetting;
use App\Models\SubtitleSetting;

/**
 * Creates a clip project with its subtitle/hook settings and queues analysis.
 * $settings has the StoreProjectRequest shape (layout_type, clip_count,
 * duration_mode, min/max_duration, subtitle{}, hook{}, music{}), so studio
 * requests and autopilot sources share one path.
 */
class ProjectCreator
{
    public function create(int $userId, string $youtubeUrl, array $settings, ?int $videoSourceId = null): ClipProject
    {
        $manual = ($settings['duration_mode'] ?? 'auto') === 'manual';

        $project = ClipProject::create([
            'user_id'         => $userId,
            'video_source_id' => $videoSourceId,
            'youtube_url'     => $youtubeUrl,
            'layout_type'     => $settings['layout_type'] ?? 'reframe',
            'clip_count'      => $settings['clip_count'] ?? 3,
            'duration_mode'   => $settings['duration_mode'] ?? 'auto',
            'min_duration'    => $manual ? ($settings['min_duration'] ?? null) : null,
            'max_duration'    => $manual ? ($settings['max_duration'] ?? null) : null,
            'music_enabled'   => $settings['music']['enabled'] ?? false,
            'music_mood'      => $settings['music']['mood'] ?? null,
            'music_volume'    => $settings['music']['volume'] ?? 15,
            'status'          => 'processing',
        ]);

        $sub = $settings['subtitle'] ?? [];
        SubtitleSetting::create([
            'clip_project_id'  => $project->id,
            'enabled'          => $sub['enabled'] ?? true,
            'font_family'      => $sub['font_family'] ?? 'Montserrat',
            'font_size'        => $sub['font_size'] ?? 42,
            'text_color'       => $sub['text_color'] ?? '#ffffff',
            'highlight_color'  => $sub['highlight_color'] ?? '#facc15',
            'position'         => $sub['position'] ?? 'bottom',
            'position_y'       => $sub['position_y'] ?? null,
            'background_style' => $sub['background_style'] ?? 'semi',
        ]);

        $hook = $settings['hook'] ?? [];
        HookSetting::create([
            'clip_project_id'  => $project->id,
            'enabled'          => $hook['enabled'] ?? true,
            'hook_text'        => $hook['hook_text'] ?? null,
            'is_ai_generated'  => $hook['is_ai_generated'] ?? false,
            'duration_seconds' => $hook['duration_seconds'] ?? 3.0,
            'position'         => $hook['position'] ?? 'center',
            'text_color'       => $hook['text_color'] ?? '#ffffff',
            'background_style' => $hook['background_style'] ?? 'semi',
        ]);

        AnalyzeVideoJob::dispatch($project->id);

        return $project;
    }

    /** Snapshot an existing project's settings, e.g. as an autopilot template. */
    public function settingsFrom(?ClipProject $project): array
    {
        if (!$project) {
            // AI-written hook: a fixed default text would be wrong for arbitrary videos
            return ['hook' => ['enabled' => true, 'is_ai_generated' => true]];
        }

        $project->loadMissing(['subtitleSetting', 'hookSetting']);
        $sub  = $project->subtitleSetting;
        $hook = $project->hookSetting;

        return [
            'layout_type'   => $project->layout_type,
            'clip_count'    => $project->clip_count,
            'duration_mode' => $project->duration_mode,
            'min_duration'  => $project->min_duration,
            'max_duration'  => $project->max_duration,
            'subtitle'      => $sub ? $sub->only(['enabled', 'font_family', 'font_size', 'text_color', 'highlight_color', 'position', 'position_y', 'background_style']) : [],
            'hook'          => $hook ? $hook->only(['enabled', 'hook_text', 'is_ai_generated', 'duration_seconds', 'position', 'text_color', 'background_style']) : [],
            'music'         => ['enabled' => $project->music_enabled, 'mood' => $project->music_mood, 'volume' => $project->music_volume],
        ];
    }
}
