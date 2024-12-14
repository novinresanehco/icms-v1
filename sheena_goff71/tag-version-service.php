<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Models\TagVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Tag\Events\TagVersionCreated;
use App\Core\Tag\Exceptions\TagVersionException;

class TagVersionService
{
    /**
     * Create a new version of a tag.
     */
    public function createVersion(Tag $tag, array $data): TagVersion
    {
        DB::beginTransaction();
        
        try {
            $version = TagVersion::create([
                'tag_id' => $tag->id,
                'name' => $data['name'] ?? $tag->name,
                'slug' => $data['slug'] ?? $tag->slug,
                'description' => $data['description'] ?? $tag->description,
                'meta_title' => $data['meta_title'] ?? $tag->meta_title,
                'meta_description' => $data['meta_description'] ?? $tag->meta_description,
                'version' => $this->getNextVersion($tag),
                'created_by' => auth()->id(),
                'changes' => $this->detectChanges($tag, $data)
            ]);

            DB::commit();
            
            event(new TagVersionCreated($version));
            
            return $version;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagVersionException("Failed to create version: {$e->getMessage()}");
        }
    }

    /**
     * Revert tag to a specific version.
     */
    public function revertToVersion(Tag $tag, int $versionId): Tag
    {
        DB::beginTransaction();

        try {
            $version = TagVersion::findOrFail($versionId);

            if ($version->tag_id !== $tag->id) {
                throw new TagVersionException("Version does not belong to this tag");
            }

            $tag->update([
                'name' => $version->name,
                'slug' => $version->slug,
                'description' => $version->description,
                'meta_title' => $version->meta_title,
                'meta_description' => $version->meta_description
            ]);

            // Create a new version to record the revert
            $this->createVersion($tag, [
                'reverted_from' => $versionId
            ]);

            DB::commit();
            
            return $tag->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagVersionException("Failed to revert version: {$e->getMessage()}");
        }
    }

    /**
     * Get version history for a tag.
     */
    public function getVersionHistory(Tag $tag): Collection
    {
        return TagVersion::where('tag_id', $tag->id)
            ->with('createdBy')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Compare two versions of a tag.
     */
    public function compareVersions(int $versionId1, int $versionId2): array
    {
        $version1 = TagVersion::findOrFail($versionId1);
        $version2 = TagVersion::findOrFail($versionId2);

        if ($version1->tag_id !== $version2->tag_id) {
            throw new TagVersionException("Versions belong to different tags");
        }

        return [
            'name' => [
                'old' => $version1->name,
                'new' => $version2->name,
                'changed' => $version1->name !== $version2->name
            ],
            'description' => [
                'old' => $version1->description,
                'new' => $version2->description,
                'changed' => $version1->description !== $version2->description
            ],
            'meta_title' => [
                'old' => $version1->meta_title,
                'new' => $version2->meta_title,
                'changed' => $version1->meta_title !== $version2->meta_title
            ],
            'meta_description' => [
                'old' => $version1->meta_description,
                'new' => $version2->meta_description,
                'changed' => $version1->meta_description !== $version2->meta_description
            ]
        ];
    }

    /**
     * Get next version number for a tag.
     */
    protected function getNextVersion(Tag $tag): int
    {
        return TagVersion::where('tag_id', $tag->id)
            ->max('version') + 1;
    }

    /**
     * Detect changes between current tag state and new data.
     */
    protected function detectChanges(Tag $tag, array $data): array
    {
        $changes = [];

        foreach ($data as $field => $value) {
            if (isset($tag->$field) && $tag->$field !== $value) {
                $changes[$field] = [
                    'old' => $tag->$field,
                    'new' => $value
                ];
            }
        }

        return $changes;
    }
}
