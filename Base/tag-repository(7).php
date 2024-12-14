<?php

namespace App\Repositories;

use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class TagRepository implements TagRepositoryInterface
{
    protected string $tagsTable = 'tags';
    protected string $taggedItemsTable = 'taggable';
    
    public function createTag(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $tagId = DB::table($this->tagsTable)->insertGetId([
                'name' => $data['name'],
                'slug' => \Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'general',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->clearTagCache();
            DB::commit();

            return $tagId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create tag: ' . $e->getMessage());
            return null;
        }
    }

    public function updateTag(int $tagId, array $data): bool
    {
        try {
            $updated = DB::table($this->tagsTable)
                ->where('id', $tagId)
                ->update([
                    'name' => $data['name'],
                    'slug' => \Str::slug($data['name']),
                    'description' => $data['description'] ?? null,
                    'type' => $data['type'] ?? 'general',
                    'updated_at' => now()
                ]) > 0;

            if ($updated) {
                $this->clearTagCache();
            }

            return $updated;
        } catch (\Exception $e) {
            \Log::error('Failed to update tag: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteTag(int $tagId): bool
    {
        try {
            DB::beginTransaction();

            // Remove tag relationships
            DB::table($this->taggedItemsTable)
                ->where('tag_id', $tagId)
                ->delete();

            // Delete tag
            $deleted = DB::table($this->tagsTable)
                ->where('id', $tagId)
                ->delete() > 0;

            if ($deleted) {
                $this->clearTagCache();
            }

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete tag: ' . $e->getMessage());
            return false;
        }
    }

    public function attachTags(string $taggableType, int $taggableId, array $tagIds): bool
    {
        try {
            DB::beginTransaction();

            // Remove existing tags
            DB::table($this->taggedItemsTable)
                ->where('taggable_type', $taggableType)
                ->where('taggable_id', $taggableId)
                ->delete();

            // Attach new tags
            $data = array_map(function($tagId) use ($taggableType, $taggableId) {
                return [
                    'tag_id' => $tagId,
                    'taggable_type' => $taggableType,
                    'taggable_id' => $taggableId,
                    'created_at' => now()
                ];
            }, $tagIds);

            DB::table($this->taggedItemsTable)->insert($data);

            $this->clearItemTagCache($taggableType, $taggableId);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to attach tags: ' . $e->getMessage());
            return false;
        }
    }

    public function detachTags(string $taggableType, int $taggableId, ?array $tagIds = null): bool
    {
        try {
            $query = DB::table($this->taggedItemsTable)
                ->where('taggable_type', $taggableType)
                ->where('taggable_id', $taggableId);

            if ($tagIds !== null) {
                $query->whereIn('tag_id', $tagIds);
            }

            $detached = $query->delete() > 0;

            if ($detached) {
                $this->clearItemTagCache($taggableType, $taggableId);
            }

            return $detached;
        } catch (\Exception $e) {
            \Log::error('Failed to detach tags: ' . $e->getMessage());
            return false;
        }
    }

    public function getTag(int $tagId): ?array
    {
        try {
            $tag = DB::table($this->tagsTable)
                ->where('id', $tagId)
                ->first();

            return $tag ? (array) $tag : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get tag: ' . $e->getMessage());
            return null;
        }
    }

    public function getTagBySlug(string $slug): ?array
    {
        try {
            $tag = DB::table($this->tagsTable)
                ->where('slug', $slug)
                ->first();

            return $tag ? (array) $tag : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get tag by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllTags(): Collection
    {
        return Cache::remember('all_tags', 3600, function() {
            return collect(DB::table($this->tagsTable)
                ->orderBy('name')
                ->get());
        });
    }

    public function getTagsByType(string $type): Collection
    {
        return $this->getAllTags()->where('type', $type);
    }

    public function getItemTags(string $taggableType, int $taggableId): Collection
    {
        $cacheKey = "item_tags_{$taggableType}_{$taggableId}";

        return Cache::remember($cacheKey, 3600, function() use ($taggableType, $taggableId) {
            return collect(DB::table($this->tagsTable)
                ->join($this->taggedItemsTable, 'tags.id', '=', 'taggable.tag_id')
                ->where('taggable.taggable_type', $taggableType)
                ->where('taggable.taggable_id', $taggableId)
                ->select('tags.*')
                ->get());
        });
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return Cache::remember("popular_tags_{$limit}", 3600, function() use ($limit) {
            return collect(DB::table($this->tagsTable)
                ->join($this->taggedItemsTable, 'tags.id', '=', 'taggable.tag_id')
                ->select('tags.*', DB::raw('COUNT(taggable.tag_id) as usage_count'))
                ->groupBy('tags.id')
                ->orderByDesc('usage_count')
                ->limit($limit)
                ->get());
        });
    }

    protected function clearTagCache(): void
    {
        Cache::forget('all_tags');
        Cache::forget('popular_tags_10');
        Cache::tags(['tags'])->flush();
    }

    protected function clearItemTagCache(string $taggableType, int $taggableId): void
    {
        Cache::forget("item_tags_{$taggableType}_{$taggableId}");
    }
}
