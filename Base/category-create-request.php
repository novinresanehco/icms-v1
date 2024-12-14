<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Category::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'type' => 'required|string|max:50',
            'status' => 'required|in:active,inactive',
            'order' => 'nullable|integer',
            'settings' => 'nullable|array',
            'meta' => 'nullable|array',
            'meta.*.key' => 'required|string|max:255',
            'meta.*.value' => 'required|string'
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'A category name is required',
            'type.required' => 'The category type must be specified',
            'status.in' => 'The status must be either active or inactive',
            'parent_id.exists' => 'The selected parent category does not exist'
        ];
    }
}
