<?php

namespace App\Core\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Media::class);
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . config('media.max_upload_size', 10240),
                'mimes:' . config('media.allowed_mimes')
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
            'disk' => ['sometimes', 'string', 'in:' . implode(',', config('media.disks'))],
            'variants' => ['sometimes', 'array']
        ];
    }
}

class UpdateMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('media'));
    }

    public function rules(): array
    {
        return [
            'file' => [
                'sometimes',
                'file',
                'max:' . config('media.max_upload_size', 10240),
                'mimes:' . config('media.allowed_mimes')
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
            'disk' => ['sometimes', 'string', 'in:' . implode(',', config('media.disks'))]
        ];
    }
}

class BulkMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:delete,update'],
            'media_ids' => ['required', 'array', 'min:1'],
            'media_ids.*' => ['required', 'exists:media,id'],
            'data' => ['required_if:action,update', 'array'],
            'data.name' => ['sometimes', 'string', 'max:255'],
            'data.metadata' => ['sometimes', 'array']
        ];
    }
}

class BatchUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Media::class);
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => [
                'required',
                'file',
                'max:' . config('media.max_upload_size', 10240),
                'mimes:' . config('media.allowed_mimes')
            ],
            'options' => ['sometimes', 'array'],
            'options.variants' => ['sometimes', 'array']
        ];
    }
}

class MediaVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Media::findOrFail($this->route('media')));
    }

    public function rules(): array
    {
        return [
            'variant' => ['required', 'string', 'in:' . implode(',', config('media.variant_types'))]
        ];
    }
}
