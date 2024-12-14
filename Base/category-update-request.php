<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->category);
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories')->ignore($this->category->id)
            ],
            'description' => 'nullable|string',
            'parent_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
                Rule::notIn([$this->category->id])
            ],
            'type' => 'sometimes|required|string|max:50',
            'status' => 'sometimes|required|in:active,inactive',
            'order' => 'nullable|integer',
            'settings' => 'nullable|array',
            'meta' => 'nullable|array',
            'meta.*.key' => 'required|string|max:255',
            'meta.*.value' => 'required|string'
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->slug) {
            $this->merge([
                'slug' => \Str::slug($this->slug)
            ]);
        }
    }
}
