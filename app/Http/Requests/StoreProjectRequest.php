<?php

namespace App\Http\Requests;

use App\Support\ClipSettings;
use App\Support\YoutubeUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'youtube_url' => ['required', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (! YoutubeUrl::canonical($value)) {
                    $fail('Link YouTube tidak valid.');
                }
            }],
        ] + ClipSettings::rules(required: true);
    }
}
