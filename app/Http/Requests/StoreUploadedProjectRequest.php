<?php

namespace App\Http\Requests;

use App\Support\ClipSettings;
use Illuminate\Foundation\Http\FormRequest;

class StoreUploadedProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A direct source upload is always allowed: no YouTube ownership check applies.
            'video' => [
                'required',
                'file',
                'extensions:mp4,mov,mkv,webm',
                'mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm',
                'max:2048000',
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ] + ClipSettings::rules(required: true);
    }

    public function messages(): array
    {
        return [
            'video.extensions' => 'Format video belum didukung. Gunakan MP4, MOV, MKV, atau WebM.',
            'video.mimetypes' => 'Berkas ini tidak terbaca sebagai video. Gunakan MP4, MOV, MKV, atau WebM.',
            'video.max' => 'Ukuran video melebihi batas unggah 2 GB.',
        ];
    }
}
