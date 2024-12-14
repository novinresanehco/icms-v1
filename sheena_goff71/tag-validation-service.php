<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Exceptions\TagValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TagValidationService
{
    /**
     * Validate tag data.
     */
    public function validateTag(array $data, ?int $excludeId = null): void
    {
        $validator = Validator::make($data, [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tags', 'name')->ignore($excludeId),
                'regex:/^[\pL\s\-\d]+$/u'
            ],
            'description' => 'nullable|string|max:1000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            throw new TagValidationException($validator);
        }
    }

    /**
     * Validate tag relationships.
     */
    public function validateTagRelationships(int $contentId, array $tagIds): void
    {
        $validator = Validator::make(
            ['tag_ids' => $tagIds],
            ['tag_ids' => 'array|max:20']
        );

        if ($validator->fails()) {
            throw new TagValidationException($validator);
        }

        $existingTags = Tag::whereIn('id', $tagIds)->pluck('id')->toArray();
        $invalidTags = array_diff($tagIds, $existingTags);

        if (!empty($invalidTags)) {
            throw new TagValidationException(
                "Invalid tag IDs: " . implode(', ', $invalidTags)
            );
        }
    }

    /**
     * Validate tag slug.
     */
    public function validateSlug(string $slug, ?int $excludeId = null): bool
    {
        return !Tag::where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    /**
     * Validate tag for merging.
     */
    public function validateTagMerge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new TagValidationException("Source and target tags cannot be the same");
        }

        if (!Tag::whereIn('id', [$sourceId, $targetId])->count() === 2) {
            throw new TagValidationException("One or both tags do not exist");
        }
    }
}
