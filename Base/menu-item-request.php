<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => 'nullable|exists:menu_items,id',
            'title' => 'required|string|max:255',
            'url' => 'required|string|max:255',
            'target' => 'nullable|string|in:_self,_blank',
            'icon' => 'nullable|string|max:255',
            'class' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
            'conditions' => 'nullable|array',
            'is_active' => 'boolean'
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Menu item title is required',
            'url.required' => 'Menu item URL is required',
            'parent_id.exists' => 'Parent menu item does not exist',
        ];
    }
}
