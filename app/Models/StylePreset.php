<?php

namespace App\Models;

use App\Support\ClipSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A named clip look: layout, edit toggles, subtitle, hook style, music, logo,
 * and whether the brand kit's font and colours go on top. Clip count, length
 * and the AI instruction are per video, so they are not part of it; neither
 * is a fixed hook text. Built-ins have no user and cannot be changed.
 */
class StylePreset extends Model
{
    public const MAX_PER_USER = 20;

    public const NAME_MAX = 60;

    private const HOOK_KEYS = ['enabled', 'duration_seconds', 'position', 'text_color', 'background_style'];

    protected $fillable = ['user_id', 'slug', 'name', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Built-ins first, then the user's own in the order they were made. */
    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->orderByRaw('user_id is not null')->orderBy('id');
    }

    public static function findVisible(mixed $id, ?int $userId): ?self
    {
        return is_numeric($id) ? self::visibleTo($userId)->find((int) $id) : null;
    }

    public function isBuiltin(): bool
    {
        return $this->user_id === null;
    }

    /** Rule for a preset_id field: a built-in or one of the user's own. */
    public static function existsRule(?int $userId): Exists
    {
        return Rule::exists('style_presets', 'id')->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }

    /** Validation for a look, under $prefix (e.g. "settings."). */
    public static function rules(string $prefix = 'settings.'): array
    {
        $keep = ['layout_type', 'edit', 'subtitle', 'hook', 'music', 'watermark'];
        $rules = array_filter(
            ClipSettings::rules($prefix),
            fn ($key) => in_array(explode('.', substr($key, strlen($prefix)))[0], $keep, true)
                && ! in_array($key, [$prefix.'hook.hook_text', $prefix.'hook.is_ai_generated'], true),
            ARRAY_FILTER_USE_KEY,
        );

        return [rtrim($prefix, '.') => ['required', 'array'], $prefix.'brand' => ['sometimes', 'boolean']] + $rules;
    }

    /** Fill every look key with its default and drop everything that is not a look. */
    public static function normalize(?array $settings): array
    {
        $settings ??= [];
        $all = ClipSettings::normalize($settings);

        return [
            'layout_type' => $all['layout_type'],
            'edit' => $all['edit'],
            'subtitle' => $all['subtitle'],
            'hook' => array_intersect_key($all['hook'], array_flip(self::HOOK_KEYS)),
            'music' => $all['music'],
            'watermark' => (bool) ($settings['watermark'] ?? false),
            'brand' => (bool) ($settings['brand'] ?? false),
        ];
    }

    /**
     * The look ready for a project (ClipSettings shape, no brand flag, AI hook). With
     * the brand flag on, the brand kit's font and colours replace the preset's
     * wherever the kit has a value.
     */
    public function look(?BrandKit $kit): array
    {
        $look = self::normalize($this->settings);
        $brand = $look['brand'] && $kit ? array_filter($kit->projectDefaults(), fn ($v) => $v !== null) : [];
        unset($look['brand']);
        // A preset has no fixed hook text, so the AI writes it (a request's own text still wins)
        $look['hook']['is_ai_generated'] = true;

        foreach (['font_family', 'highlight_color', 'text_color'] as $key) {
            if (isset($brand[$key])) {
                $look['subtitle'][$key] = $brand[$key];
            }
        }
        foreach (['text_color', 'background_style'] as $key) {
            if (isset($brand[$key])) {
                $look['hook'][$key] = $brand[$key];
            }
        }

        return $look;
    }

    /** $settings with this preset's look under it: whatever $settings sets itself still wins. */
    public function under(array $settings, ?BrandKit $kit): array
    {
        return array_replace_recursive($this->look($kit), $settings);
    }

    /** What sets this preset apart, in short words for chips: "Bebas Neue pop", "Musik energik", "Efek suara"... */
    public function summary(): array
    {
        $s = self::normalize($this->settings);
        $styles = ['karaoke' => 'karaoke', 'pop' => 'pop', 'word' => 'per kata'];
        $moods = ['energetic' => 'energik', 'chill' => 'santai', 'inspiring' => 'inspiratif', 'dramatic' => 'dramatis', 'funny' => 'lucu', 'sad' => 'sedih'];

        return array_values(array_filter([
            $s['brand'] ? 'Brand kit' : null,
            ['gaussian' => 'Gaussian', 'gameplay' => 'Split gameplay'][$s['layout_type']] ?? null,
            $s['subtitle']['enabled'] ? $s['subtitle']['font_family'].' '.($styles[$s['subtitle']['style']] ?? '') : 'Tanpa subtitle',
            $s['hook']['enabled'] ? null : 'Tanpa hook',
            $s['edit']['title'] ? 'Judul di atas' : null,
            $s['music']['enabled'] ? trim('Musik '.($moods[$s['music']['mood']] ?? '')) : null,
            $s['edit']['sfx'] ? ($s['edit']['sfx_style'] === 'energetic' ? 'Efek suara energik' : 'Efek suara') : null,
            $s['edit']['broll'] ? "B\u{2011}roll" : null, // non-breaking hyphen: never split across lines
            $s['edit']['zoom'] ? 'Zoom' : null,
        ]));
    }
}
