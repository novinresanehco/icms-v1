<?php

namespace App\Http\Requests;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string']
        ];
    }
}

class StoreUserRequest extends FormRequest 
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'exists:roles,name']
        ];
    }

    public function authorize(): bool
    {
        return $this->user()->can('create-users');
    }
}

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $this->user],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'exists:roles,name']
        ];
    }

    public function authorize(): bool
    {
        return $this->user()->can('update-users');
    }
}

class StoreContentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'category_id' => ['required', 'exists:categories,id'],
            'tags' => ['array'],
            'tags.*' => ['exists:tags,id']
        ];
    }

    public function authorize(): bool
    {
        return $this->user()->can('create-content');
    }
}

class UpdateContentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:draft,published'],
            'category_id' => ['sometimes', 'exists:categories,id'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['exists:tags,id']
        ];
    }

    public function authorize(): bool
    {
        return $this->user()->can('update-content');
    }
}

class StoreMediaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'media' => ['required', 'file', 'max:10240', 'mimes:jpg,png,pdf,doc,docx']
        ];
    }

    public function authorize(): bool
    {
        return $this->user()->can('upload-media');
    }
}

class RenderTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'template' => ['required', 'string'],
            'data' => ['required', 'array']
        ];
    }

    public function authorize(): bool
    {
        return $this->user()->can('render-templates');
    }
}
