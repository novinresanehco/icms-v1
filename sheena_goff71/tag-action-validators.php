<?php

namespace App\Core\Tag\Services\Actions\Validators;

use App\Core\Tag\Services\Actions\DTOs\{TagCreateData, TagUpdateData};
use App\Core\Tag\Exceptions\TagValidationException;
use Illuminate\Support\Facades\Validator;

class TagActionValidator
{
    protected array $createRules = [
        'name' => 'required|string|max:255|unique:tags,name',
        'description' => 'nullable|string|max:1000',
        'meta_title' => 'nullable|string|max:255',
        'meta_description' => 'nullable|string|max:1000',
        'relationships.content_ids.*' => 'exists:contents,id',
        'relationships.parent_ids.*' => 'exists:tags,id',
        'relationships.child_ids.*' => 'exists:tags,id',
        'metadata' => 'array'
    ];

    protected array $updateRules = [
        'name' => 'sometimes|required|string|max:255',
        'description' => 'nullable|string|max:1000',
        'meta_title' => 'nullable|string|max:255',
        'meta_description' => 'nullable|string|max:1000',
        'relationships.content_ids.*' => 'exists:contents,id',
        'relationships.parent_ids.*' => 'exists:tags,id',
        'relationships.child_ids.*' => 'exists:tags,id',
        'metadata' => 'array'
    ];

    protected array $messages = [
        'name.required' => 'Tag name is required',
        'name.unique' => 'Tag name already exists',
        'name.max' => 'Tag name cannot exceed 255 characters',
        'description.max' => 'Description cannot exceed 1000 characters',
        'relationships.content_ids.*.exists' => 'One or more content IDs are invalid',
        'relationships.parent_ids.*.exists' => 'One or more parent tag IDs are invalid',
        'relationships.child_ids.*.exists' => 'One or more child tag IDs are invalid'
    ];

    public function validateCreate(TagCreateData $data): void
    {
        $validator = Validator::make(
            $data->toArray(),
            $this->createRules,
            $this->messages
        );

        if ($validator->fails()) {
            throw new TagValidationException($validator->errors()->toArray());
        }

        $this->validateBusinessRules($data);
    }

    public function validateUpdate(TagUpdateData $data): void
    {
        $rules = $this->updateRules;
        $rules['name'] .= ',name,' . $data->id;

        $validator = Validator::make(
            $data->toArray(),
            $rules,
            $this->messages
        );

        if ($validator->fails()) {
            throw new TagValidationException($validator->errors()->toArray());
        }

        $this->validateBusinessRules($data);
    }

    protected function validateBusinessRules($data): void
    {
        $this->validateHierarchy($data);
        $this->validateNamingConventions($data);
        $this->validateMetadata($data);
        $this->validateRelationships($data);
    }

    protected function validateHierarchy($data): void
    {
        if (empty($data->relationships)) {
            return;
        }

        $parentIds = $data->relationships['parent_ids'] ?? [];
        $childIds = $data->relationships['child_ids'] ?? [];

        // Check for circular dependencies
        if (array_intersect($parentIds, $childIds)) {
            throw new TagValidationException([
                'relationships' => ['Tag cannot be both parent and child']
            ]);
        }
    }

    protected function validateNamingConventions($data): void
    {
        if (!isset($data->name)) {
            return;
        }

        // Check for valid characters
        if (!preg_match('/^[\pL\s\-\d]+$/u', $data->name)) {
            throw new TagValidationException([
                'name' => ['Tag name contains invalid characters']
            ]);
        }

        // Check for reserved words
        $reservedWords = ['admin', 'system', 'default'];
        if (in_array(strtolower($data->name), $reservedWords)) {
            throw new TagValidationException([
                'name' => ['Tag name uses a reserved word']
            ]);
        }
    }

    protected function validateMetadata($data): void
    {
        if (empty($data->metadata)) {
            return;
        }

        $validator = Validator::make($data->metadata, [
            'author_id' => 'nullable|exists:users,id',
            'visibility' => 'nullable|in:public,private,restricted',
            'expires_at' => 'nullable|date|after:now'
        ]);

        if ($validator->fails()) {
            throw new TagValidationException([
                'metadata' => $validator->errors()->toArray()
            ]);
        }
    }

    protected function validateRelationships($data): void
    {
        if (empty($data->relationships)) {
            return;
        }

        // Validate relationship limits
        if (isset($data->relationships['content_ids']) && 
            count($data->relationships['content_ids']) > 1000) {
            throw new TagValidationException([
                'relationships' => ['Too many content relationships']
            ]);
        }

        // Validate relationship types
        if (isset($data->relationships['parent_ids'])) {
            $this->validateParentRelationships($data->relationships['parent_ids']);
        }
    }

    protected function validateParentRelationships(array $parentIds): void
    {
        // Add any specific parent relationship validation logic
        if (count($parentIds) > 5) {
            throw new TagValidationException([
                'relationships' => ['Maximum of 5 parent tags allowed']
            ]);
        }
    }
}
