// File: app/Core/Tag/Validation/TagValidator.php
<?php

namespace App\Core\Tag\Validation;

class TagValidator
{
    protected array $rules = [
        'name' => 'required|string|max:50|unique:tags',
        'description' => 'nullable|string|max:255',
        'type' => 'nullable|string|in:default,system,user',
        'metadata' => 'nullable|array'
    ];

    protected array $updateRules = [
        'name' => 'required|string|max:50|unique:tags,name,%id%',
        'description' => 'nullable|string|max:255',
        'type' => 'nullable|string|in:default,system,user',
        'metadata' => 'nullable|array'
    ];

    public function validate(array $data): bool
    {
        $validator = Validator::make($data, $this->rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return true;
    }

    public function validateUpdate(int $id, array $data): bool
    {
        $rules = array_map(function($rule) use ($id) {
            return str_replace('%id%', $id, $rule);
        }, $this->updateRules);

        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return true;
    }
}
