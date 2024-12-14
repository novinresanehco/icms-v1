<?php

namespace App\Core\Content\Validators;

use App\Core\Content\Exceptions\ContentValidationException;
use App\Core\Content\Models\Content;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContentValidator
{
    /**
     * Validation rules for content creation
     *
     * @var array
     */
    private array $creationRules = [
        'title' => ['required', 'string', 'max:255'],
        'slug' => ['required', 'string', 'max:255', 'unique:contents'],
        'content' => ['required', 'string'],
        'type' => ['required', 'string', 'in:post,page,article'],
        'category_id' => ['required', 'integer', 'exists:categories,id'],
        'tags' => ['sometimes', 'array'],
        'tags.*' => ['integer', 'exists:tags,id'],
        'meta_title' => ['nullable', 'string', 'max:60'],
        'meta_description' => ['nullable', 'string', 'max:160'],
        'status' => ['required', 'string', 'in:draft,published,archived'],
        'author_id' => ['required', 'integer', 'exists:users,id'],
        'published_at' => ['nullable', 'date'],
        'featured_image' => ['nullable', 'string', 'max:255']
    ];

    /**
     * Validation rules for content update
     *
     * @var array
     */
    private array $updateRules = [
        'title' => ['sometimes', 'string', 'max:255'],
        'slug' => ['sometimes', 'string', 'max:255'],
        'content' => ['sometimes', 'string'],
        'type' => ['sometimes', 'string', 'in:post,page,article'],
        'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
        'tags' => ['sometimes', 'array'],
        'tags.*' => ['integer', 'exists:tags,id'],
        'meta_title' => ['nullable', 'string', 'max:60'],
        'meta_description' => ['nullable', 'string', 'max:160'],
        'status' => ['sometimes', 'string', 'in:draft,published,archived'],
        'published_at' => ['nullable', 'date'],
        'featured_image' => ['nullable', 'string', 'max:255']
    ];

    /**
     * Custom validation messages
     *
     * @var array
     */
    private array $messages = [
        'title.required' => 'The content title is required.',
        'title.max' => 'The title cannot exceed 255 characters.',
        'slug.unique' => 'This URL slug is already in use.',
        'content.required' => 'The content body is required.',
        'type.in' => 'Invalid content type specified.',
        'category_id.exists' => 'The selected category does not exist.',
        'tags.*.exists' => 'One or more selected tags do not exist.',
        'meta_title.max' => 'Meta title should not exceed 60 characters for SEO optimization.',
        'meta_description.max' => 'Meta description should not exceed 160 characters for SEO optimization.',
        'status.in' => 'Invalid content status specified.',
        'author_id.exists' => 'The specified author does not exist.',
        'published_at.date' => 'Invalid publication date format.'
    ];

    /**
     * Validate content data for creation
     *
     * @param array $data Content data to validate
     * @throws ContentValidationException
     * @return bool
     */
    public function validateForCreation(array $data): bool
    {
        $validator = Validator::make($data, $this->getCreationRules($data), $this->messages);

        if ($validator->fails()) {
            throw new ContentValidationException($validator->errors()->first());
        }

        return true;
    }

    /**
     * Validate content data for update
     *
     * @param int $id Content ID
     * @param array $data Content data to validate
     * @throws ContentValidationException
     * @return bool
     */
    public function validateForUpdate(int $id, array $data): bool
    {
        $rules = $this->getUpdateRules($id, $data);
        $validator = Validator::make($data, $rules, $this->messages);

        if ($validator->fails()) {
            throw new ContentValidationException($validator->errors()->first());
        }

        return true;
    }

    /**
     * Get creation rules with dynamic validation
     *
     * @param array $data Input data
     * @return array
     */
    protected function getCreationRules(array $data): array
    {
        $rules = $this->creationRules;

        // Add conditional rules based on content type
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'article':
                    $rules['excerpt'] = ['required', 'string', 'max:500'];
                    break;
                case 'page':
                    $rules['parent_id'] = ['nullable', 'integer', 'exists:contents,id'];
                    break;
            }
        }

        return $rules;
    }

    /**
     * Get update rules with dynamic validation
     *
     * @param int $id Content ID
     * @param array $data Input data
     * @return array
     */
    protected function getUpdateRules(int $id, array $data): array
    {
        $rules = $this->updateRules;

        // Modify slug uniqueness rule for updates
        if (isset($data['slug'])) {
            $rules['slug'] = [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('contents')->ignore($id)
            ];
        }

        // Add conditional rules based on content type
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'article':
                    $rules['excerpt'] = ['sometimes', 'string', 'max:500'];
                    break;
                case 'page':
                    $rules['parent_id'] = ['nullable', 'integer', 'exists:contents,id'];
                    break;
            }
        }

        return $rules;
    }

    /**
     * Validate content status transition
     *
     * @param Content $content Current content instance
     * @param string $newStatus Desired status
     * @throws ContentValidationException
     * @return bool
     */
    public function validateStatusTransition(Content $content, string $newStatus): bool
    {
        $allowedTransitions = [
            'draft' => ['published', 'archived'],
            'published' => ['archived'],
            'archived' => ['draft']
        ];

        if (!isset($allowedTransitions[$content->status]) ||
            !in_array($newStatus, $allowedTransitions[$content->status])) {
            throw new ContentValidationException(
                "Invalid status transition from {$content->status} to {$newStatus}"
            );
        }

        return true;
    }

    /**
     * Validate content publishing requirements
     *
     * @param Content $content Content to validate
     * @throws ContentValidationException
     * @return bool
     */
    public function validatePublishingRequirements(Content $content): bool
    {
        $requirements = [
            'title' => !empty($content->title),
            'content' => !empty($content->content),
            'category' => !empty($content->category_id),
            'meta_title' => !empty($content->meta_title),
            'meta_description' => !empty($content->meta_description)
        ];

        $missing = array_keys(array_filter($requirements, fn($met) => !$met));

        if (!empty($missing)) {
            throw new ContentValidationException(
                'Publishing requirements not met. Missing: ' . implode(', ', $missing)
            );
        }

        return true;
    }
}
