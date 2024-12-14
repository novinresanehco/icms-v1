<?php

namespace App\Core\Interfaces;

interface ValidatorInterface
{
    public function validate(array $data, ?int $id = null): void;
    public function getValidationRules(?int $id = null): array;
}

class ContentValidator implements ValidatorInterface
{
    public function validate(array $data, ?int $id = null): void
    {
        $rules = $this->getValidationRules($id);
        validator($data, $rules)->validate();
    }

    public function getValidationRules(?int $id = null): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:contents,slug' . ($id ? ",{$id}" : ''),
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'published_at' => 'nullable|date',
            'author_id' => 'required|exists:users,id'
        ];
    }
}

class TagValidator implements ValidatorInterface
{
    public function validate(array $data, ?int $id = null): void
    {
        $rules = $this->getValidationRules($id);
        validator($data, $rules)->validate();
    }

    public function getValidationRules(?int $id = null): array
    {
        return [
            'name' => 'required|string|max:50|unique:tags,name' . ($id ? ",{$id}" : ''),
            'slug' => 'required|string|max:50|unique:tags,slug' . ($id ? ",{$id}" : ''),
            'description' => 'nullable|string|max:255'
        ];
    }
}

class MediaValidator implements ValidatorInterface
{
    public function validate(array $data, ?int $id = null): void
    {
        $rules = $this->getValidationRules($id);
        validator($data, $rules)->validate();
    }

    public function getValidationRules(?int $id = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'file_path' => 'required|string|max:255',
            'mime_type' => 'required|string|max:100',
            'size' => 'required|integer',
            'type' => 'required|in:image,video,document,other',
            'alt_text' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255'
        ];
    }
}
