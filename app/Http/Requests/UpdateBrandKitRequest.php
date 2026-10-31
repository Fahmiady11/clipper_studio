<?php

namespace App\Http\Requests;

use App\Support\ClipSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandKitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $color = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'primary_color' => $color,
            'secondary_color' => $color,
            'font_family' => ['nullable', 'string', Rule::in(ClipSettings::FONT_FAMILIES)],
            'hook_style' => ['required', 'string', Rule::in(['none', 'semi', 'full'])],
        ];
    }
}
