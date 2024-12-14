<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Services\Actions\DTOs\{TagCreateData, TagUpdateData};
use App\Exceptions\TagValidationException;
use Illuminate\Support\Facades\Validator;

class TagValidator
{
    public function validateCreate(TagCreateData $data): void
    {
        $validator = Validator::make($data->toArray(), [
            'name' => 'required|string|max:255|unique:tags,name',
            'description' => 'nullable|string|max:1000',
            'metadata' => 'nullable|array',
            'parent_id' => 'nullable|exists:tags,id'
        ]);

        if ($validator->fails()) {
            throw new TagValidationException(
                'Tag validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateUpdate(Tag $tag, TagUpdateData $data): void
    {
        $validator = Validator::make($data->toArray(), [
            'name' => "nullable|string|max:255|unique:tags,name,{$tag->id}",
            'description' => 'nullable|string|max:1000',
            'metadata' => 'nullable|array',
            'parent_id' => 'nullable|exists:tags,id'
        ]);

        if ($validator->fails()) {
            throw new TagValidationException(
                'Tag validation failed',
                $validator->errors()->toArray()
            );
        }

        // Prevent circular references
        if ($data->parentId && $this->wouldCreateCircularReference($tag, $data->parentId)) {
            throw new TagValidationException(
                'Cannot create circular reference in tag hierarchy'
            );
        }
    }

    public function validateDelete(Tag $tag): void
    {
        // Check if tag can be deleted
        if ($tag->isProtected()) {
            throw new TagValidationException('Cannot delete protected tag');
        }

        // Check for dependent relationships
        if ($tag->hasActiveRelationships()) {
            throw new TagValidationException(
                'Cannot delete tag with active relationships'
            );
        }
    }

    public function validateBulkAction(string $action, array $tagIds, array $data = []): void
    {
        if (!in_array($action, ['delete', 'update'])) {
            throw new TagValidationException("Invalid bulk action: {$action}");
        }

        if (empty($tagIds)) {
            throw new TagValidationException('No tags specified for bulk action');
        }

        if ($action === 'update' && empty($data)) {
            throw new TagValidationException('No data provided for bulk update');
        }
    }

    protected function wouldCreateCircularReference(Tag $tag, int $newParentId): bool
    {
        if ($tag->id === $newParentId) {
            return true;
        }

        $parentTag = Tag::find($newParentId);
        while ($parentTag) {
            if ($parentTag->id === $tag->id) {
                return true;
            }
            $parentTag = $parentTag->parent;
        }

        return false;
    }
}
