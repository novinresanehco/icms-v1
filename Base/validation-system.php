<?php

namespace App\Core\Validation;

interface ValidatorInterface
{
    public function validate(array $data, $id = null): bool;
    public function getErrors(): array;
}

class BaseValidator implements ValidatorInterface
{
    protected array $rules = [];
    protected array $errors = [];

    public function validate(array $data, $id = null): bool
    {
        $rules = $this->getRules($id);
        $validator = validator($data, $rules);

        if ($validator->fails()) {
            $this->errors = $validator->errors()->toArray();
            return false;
        }

        return true;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    protected function getRules($id = null): array
    {
        return $this->rules;
    }
}

class PageValidator extends BaseValidator
{
    protected array $rules = [
        'title' => 'required|min:3|max:255',
        'slug' => 'required|alpha_dash|unique:pages,slug',
        'content' => 'required',
        'status' => 'required|in:draft,published,archived',
        'author_id' => 'required|exists:users,id'
    ];

    protected function getRules($id = null): array
    {
        $rules = $this->rules;
        
        if ($id) {
            // Modify unique rule for updates
            $rules['slug'] = "required|alpha_dash|unique:pages,slug,{$id}";
        }

        return $rules;
    }
}

// Factory for creating validators
class ValidatorFactory
{
    public function make(string $type): ValidatorInterface
    {
        return match($type) {
            'page' => new PageValidator(),
            default => throw new \InvalidArgumentException("Unknown validator type: {$type}")
        };
    }
}
