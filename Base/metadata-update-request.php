<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MetadataUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'metadata' => ['required', 'array'],
            'metadata.*' => ['sometimes', 'string']
        ];
    }
}
