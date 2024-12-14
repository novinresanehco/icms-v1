<?php

namespace App\Core\Repositories\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class AbstractValidator
{
    protected array $rules = [];
    protected array $messages = [];
    protected array $customAttributes = [];

    public function validate(array $data): array
    {
        $validator = Validator::make(
            $data,
            $this->getRules($data),
            $this->getMessages(),
            $this->getCustomAttributes()
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    protected function getRules(array $data): array
    {
        return $this->rules;
    }

    protected function getMessages(): array
    {
        return $this->messages;
    }

    protected function getCustomAttributes(): array
    {
        return $this->customAttributes;
    }
}

namespace App\Core\Repositories\Validation\Validators;

use App\Core\Repositories\Validation\AbstractValidator;

class PageValidator extends AbstractValidator
{
    protected array $rules = [
        'title' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:pages,slug',
        'content' => 'required|string',
        'template' => 'required|string|max:100',
        'status' => 'required|in:draft,published,scheduled',
        'metadata' => 'nullable|array',
        'parent_id' => 'nullable|exists:pages,id',
        'order' => 'nullable|integer',
        'published_at' => 'nullable|date'
    ];

    protected array $messages = [
        'title.required' => 'The page title is required.',
        'slug.unique' => 'This URL slug is already in use.',
        'template.required' => 'Please select a template.',
        'status.in' => 'Invalid page status selected.'
    ];

    public function getRules(array $data): array
    {
        $rules = $this->rules;
        
        // Modify slug uniqueness rule for updates
        if (isset($data['id'])) {
            $rules['slug'] = "required|string|max:255|unique:pages,slug,{$data['id']}";
        }
        
        return $rules;
    }
}
