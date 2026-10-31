<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandKit extends Model
{
    protected $fillable = ['user_id', 'logo_path', 'primary_color', 'secondary_color', 'font_family', 'hook_style'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Brand defaults the studio starts from: project defaults plus whether a logo exists. */
    public function studioDefaults(): array
    {
        return $this->projectDefaults() + ['has_logo' => $this->logo_path !== null];
    }

    /**
     * Default values for a new project. Null means "no brand value": the
     * caller falls back to the built-in default for that key.
     *
     * @return array{font_family: ?string, highlight_color: ?string, text_color: ?string, background_style: string, watermark: bool}
     */
    public function projectDefaults(): array
    {
        return [
            'font_family' => $this->font_family,
            'highlight_color' => $this->primary_color,
            'text_color' => $this->secondary_color,
            'background_style' => $this->hook_style,
            'watermark' => $this->logo_path !== null,
        ];
    }
}
