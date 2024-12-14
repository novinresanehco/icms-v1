<?php

namespace App\Core\Category\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Category::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['boolean']
        ];
    }
}

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('category'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255', 'unique:categories,name,' . $this->route('category')],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug,' . $this->route('category')],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['boolean']
        ];
    }
}

class MoveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Category::findOrFail($this->route('id')));
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'exists:categories,id'],
            'position' => ['nullable', 'integer', 'min:0']
        ];
    }
}

class BulkCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:delete,update,move'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'exists:categories,id'],
            'data' => ['required_if:action,update,move', 'array'],
            'data.parent_id' => ['required_if:action,move', 'nullable', 'exists:categories,id']
        ];
    }
}

class CategoryAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignContent', Category::findOrFail($this->route('id')));
    }

    public function rules(): array
    {
        return [
            'content_ids' => ['required', 'array', 'min:1'],
            'content_ids.*' => ['required', 'exists:contents,id']
        ];
    }
}
