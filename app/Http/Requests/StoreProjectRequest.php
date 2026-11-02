<?php

namespace App\Http\Requests;

use App\Models\StylePreset;
use App\Support\ClipSettings;
use App\Support\YoutubeUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** preset_id: the preset's look under whatever the request sets itself (the agent API merges it earlier). */
    protected function prepareForValidation(): void
    {
        $preset = StylePreset::findVisible($this->input('preset_id'), $this->user()?->id);
        if ($preset) {
            $this->replace($preset->under($this->all(), $this->user()->brandKit));
        }
    }

    public function rules(): array
    {
        return [
            'youtube_url' => ['required', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (! YoutubeUrl::canonical($value)) {
                    $fail('Link YouTube tidak valid.');
                }
            }],
            'preset_id' => ['sometimes', 'nullable', 'integer', StylePreset::existsRule($this->user()?->id)],
        ] + ClipSettings::rules(required: true);
    }
}
