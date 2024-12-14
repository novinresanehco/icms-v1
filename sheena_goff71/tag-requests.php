<?php

namespace App\Core\Tag\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Tag::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:tags,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
            'parent_id' => ['nullable', 'exists:tags,id'],
            'is_protected' => ['nullable', 'boolean']
        ];
    }
}

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tag'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255', 'unique:tags,name,' . $this->route('tag')],
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
            'parent_id' => ['nullable', 'exists:tags,id'],
            'is_protected' => ['nullable', 'boolean']
        ];
    }
}

class BulkTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:delete,update'],
            'tag_ids' => ['required', 'array', 'min:1'],
            'tag_ids.*' => ['required', 'exists:tags,id'],
            'data' => ['required_if:action,update', 'array'],
            'data.name' => ['nullable', 'string', 'max:255'],
            'data.description' => ['nullable', 'string', 'max:1000'],
            'data.metadata' => ['nullable', 'array'],
            'data.parent_id' => ['nullable', 'exists:tags,id']
        ];
    }
}

class TagAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attachToContent', Tag::class);
    }

    public function rules(): array
    {
        return [
            'tag_ids' => ['required', 'array', 'min:1'],
            'tag_ids.*' => ['required', 'exists:tags,id']
        ];
    }
}
