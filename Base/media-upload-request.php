<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
            'type' => ['sometimes', 'string', 'in:image,video,document,audio']
        ];
    }
}
