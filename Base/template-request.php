<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:templates,slug',
            'description' => 'nullable|string',
            'content' => 'required|string',
            'type' => 'required|string|in:page,partial,layout',
            'category' => 'required|string',
            'status' => 'required|string|in:active,inactive,draft',
            'variables' => 'nullable|array',
            'settings' => 'nullable|array',
            'regions' => 'nullable|array',
            'regions.*.name' => 'required|string|max:255',
            'regions.*.content' => 'required|string',
            'regions.*.settings' => 'nullable|array'
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['slug'] = 'nullable|string|max:255|unique:templates,slug,' . $this->route('template')->id;
        }

        return $rules;
    }
}
