<?php

namespace App\Support;

use App\Services\MusicService;

/**
 * The look of generated clips (layout, clip count/length, subtitle, hook,
 * music) in the shape ProjectCreator consumes. Shared by the studio's
 * project request and autopilot sources so both validate the same way.
 */
final class ClipSettings
{
    /** Bounds for a dragged subtitle (% of frame height) — keeps it fully on screen. */
    public const Y_MIN = 5;

    public const Y_MAX = 95;

    public const SUBTITLE_STYLES = ['karaoke', 'pop', 'word'];

    public const DEFAULTS = [
        'layout_type' => 'reframe',
        'clip_count' => 3,
        'duration_mode' => 'auto',
        'min_duration' => 20,
        'max_duration' => 60,
        'edit' => ['jump_cut' => true, 'zoom' => true, 'progress' => true, 'title' => false, 'broll' => true],
        'subtitle' => [
            'enabled' => true, 'font_family' => 'Montserrat', 'font_size' => 56, 'text_color' => '#ffffff',
            'highlight_color' => '#facc15', 'position' => 'bottom', 'position_y' => null, 'background_style' => 'semi',
            'style' => 'karaoke', 'emoji' => true,
        ],
        'hook' => [
            'enabled' => true, 'hook_text' => null, 'is_ai_generated' => true, 'duration_seconds' => 3.0,
            'position' => 'center', 'text_color' => '#ffffff', 'background_style' => 'full',
        ],
        'music' => ['enabled' => false, 'mood' => null, 'volume' => 25],
    ];

    /**
     * Validation rules, optionally nested under $prefix (e.g. "settings.").
     * $required: the top-level layout/count/mode keys must be present.
     */
    public static function rules(string $prefix = '', bool $required = false): array
    {
        $need = $required ? 'required' : 'sometimes';
        $color = ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        $rules = [
            'layout_type' => [$need, 'in:reframe,gaussian'],
            'clip_count' => [$need, 'integer', 'in:1,3,5'],
            'duration_mode' => [$need, 'in:auto,manual'],
            'min_duration' => ['nullable', 'integer', 'min:5', 'max:300'],
            'max_duration' => ['nullable', 'integer', 'min:10', 'max:600'],

            'edit' => ['sometimes', 'array'],
            'edit.jump_cut' => ['sometimes', 'boolean'],
            'edit.zoom' => ['sometimes', 'boolean'],
            'edit.progress' => ['sometimes', 'boolean'],
            'edit.title' => ['sometimes', 'boolean'],
            'edit.broll' => ['sometimes', 'boolean'],

            'subtitle' => ['sometimes', 'array'],
            'subtitle.enabled' => ['sometimes', 'boolean'],
            'subtitle.font_family' => ['sometimes', 'string', 'in:Arial,Roboto,Montserrat,Oswald,Bebas Neue'],
            'subtitle.font_size' => ['sometimes', 'integer', 'min:16', 'max:160'],
            'subtitle.text_color' => $color,
            'subtitle.highlight_color' => $color,
            'subtitle.position' => ['sometimes', 'in:top,center,bottom'],
            'subtitle.position_y' => ['sometimes', 'nullable', 'numeric', 'min:'.self::Y_MIN, 'max:'.self::Y_MAX],
            'subtitle.background_style' => ['sometimes', 'in:none,semi,full'],
            'subtitle.emoji' => ['sometimes', 'boolean'],
            'subtitle.style' => ['sometimes', 'in:'.implode(',', self::SUBTITLE_STYLES)],

            'hook' => ['sometimes', 'array'],
            'hook.enabled' => ['sometimes', 'boolean'],
            'hook.hook_text' => ['sometimes', 'nullable', 'string', 'max:100'],
            'hook.is_ai_generated' => ['sometimes', 'boolean'],
            'hook.duration_seconds' => ['sometimes', 'numeric', 'min:2', 'max:5'],
            'hook.position' => ['sometimes', 'in:top,center,bottom'],
            'hook.text_color' => $color,
            'hook.background_style' => ['sometimes', 'in:none,semi,full'],

            'music' => ['sometimes', 'array'],
            'music.enabled' => ['sometimes', 'boolean'],
            'music.mood' => ['sometimes', 'nullable', 'in:'.implode(',', MusicService::MOODS)],
            'music.volume' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];

        if ($prefix === '') {
            return $rules;
        }

        $nested = [rtrim($prefix, '.') => ['sometimes', 'array']];
        foreach ($rules as $key => $rule) {
            $nested[$prefix.$key] = $rule;
        }

        return $nested;
    }

    /** Fill every missing key with its default, dropping unknown ones. */
    public static function normalize(?array $settings): array
    {
        $settings ??= [];
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $settings[$key] ?? null;
            $out[$key] = is_array($default)
                ? array_merge($default, array_intersect_key(array_filter((array) $value, fn ($v) => $v !== null), $default))
                : ($value ?? $default);
        }

        return $out;
    }

    /**
     * Autopilot clips come from videos nobody has seen yet, so a fixed hook
     * text would be wrong for every one of them: the hook is always AI-written.
     */
    public static function forAutopilot(?array $settings): array
    {
        $out = self::normalize($settings);
        $out['hook']['is_ai_generated'] = true;
        $out['hook']['hook_text'] = null;

        return $out;
    }
}
