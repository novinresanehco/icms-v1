<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Contracts\TagValidatorInterface;
use Illuminate\Support\Facades\Validator;
use App\Core\Tag\Models\Tag;

class TagValidationService implements TagValidatorInterface
{
    /**
     * @var array
     */
    protected array $errors = [];

    /**
     * Validation rules.
     */
    protected array $rules = [
        'name' => 'required|string|max:255|unique:tags,name',
        'slug' => 'nullable|string|max:255|unique:tags,slug',
        'description' => 'nullable|string|max:1000',
        'meta_title' => 'nullable|string|max:255',
        'meta_description' => 'nullable|string|max:1000',
        'relationships.content_ids.*' => 'exists:contents,id',
        'relationships.parent_ids.*' => 'exists:tags,id',
        'relationships.child_ids.*' => 'exists:tags,id'
    ];

    /**
     * Custom validation messages.
     */
    protected array $messages = [
        'name.required' => 'Tag name is required',
        'name.unique' => 'Tag name already exists',
        'slug.unique' => 'Tag slug already exists',
    ];

    /**
     * Validate tag data.
     */
    public function validate(array $data, ?int $ignoreId = null): bool
    {
        $rules = $this->getRules($ignoreId);

        $validator = Validator::make($data, $rules, $this->messages);

        if ($validator->fails()) {
            $this->errors = $validator->errors()->toArray();
            return false;
        }

        return $this->validateBusinessRules($data);
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get validation rules.
     */
    protected function getRules(?int $ignoreId = null): array
    {
        $rules = $this->rules;

        if ($ignoreId) {
            $rules['name'] .= ',' . $ignoreId;
            $rules['slug'] .= ',' . $ignoreId;
        }

        return $rules;
    }

    /**
     * Validate business rules.
     */
    protected function validateBusinessRules(array $data): bool
    {
        // Check for circular dependencies in hierarchy
        if (isset($data['relationships'])) {
            if (!$this->validateHierarchy($data['relationships'])) {
                return false;
            }
        }

        // Additional business validations
        if (isset($data['name']) && !$this->validateTagNaming($data['name'])) {
            return false;
        }

        return true;
    }

    /**
     * Validate tag hierarchy.
     */
    protected function validateHierarchy(array $relationships): bool
    {
        if (isset($relationships['parent_ids']) && isset($relationships['child_ids'])) {
            $intersection = array_intersect(
                $relationships['parent_ids'], 
                $relationships['child_ids']
            );

            if (!empty($intersection)) {
                $this->errors['hierarchy'] = ['A tag cannot be both a parent and child'];
                return false;
            }
        }

        return true;
    }

    /**
     * Validate tag naming conventions.
     */
    protected function validateTagNaming(string $name): bool
    {
        // Check for valid characters
        if (!preg_match('/^[\pL\s\-\d]+$/u', $name)) {
            $this->errors['name'] = ['Tag name contains invalid characters'];
            return false;
        }

        // Check for reserved words
        $reservedWords = ['admin', 'system', 'default'];
        if (in_array(strtolower($name), $reservedWords)) {
            $this->errors['name'] = ['Tag name uses a reserved word'];
            return false;
        }

        return true;
    }
}
