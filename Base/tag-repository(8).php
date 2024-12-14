<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Tag();
    }

    public function findBySlug(string $slug): ?Tag
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function findByName(string $name): ?Tag
    {
        return $this->model->where('name', $name)->first();
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->model->orderBy('usage_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public function syncContentTags(int $contentId, array $tags): bool
    {
        try {
            $tagIds = [];
            
            foreach ($tags as $tagName) {
                $tag = $this->model->firstOrCreate(
                    ['name' => $tagName],
                    ['slug' => Str::slug($tagName)]
                );
                
                $tagIds[] = $tag->id;
            }
            
            $content = \App\Models\Content::findOrFail($contentId);
            $content->tags()->sync($tagIds);
            
            $this->updateTagsUsageCount($tagIds);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createWithMetadata(string $name, array $metadata = []): Tag
    {
        $tag = $this->model->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'metadata' => $metadata
        ]);

        return $tag;
    }

    public function mergeTags(int $sourceId, int $targetId): bool
    {
        try {
            \DB::beginTransaction();

            $source = $this->model->findOrFail($sourceId);
            $target = $this->model->findOrFail($targetId);

            // Update content relationships
            \DB::table('content_tag')
                ->where('tag_id', $sourceId)
                ->update(['tag_id' => $targetId]);

            // Merge metadata
            $target->metadata = array_merge(
                $target->metadata ?? [],
                $source->metadata ?? []
            );
            $target->save();

            // Update usage count
            $target->increment('usage_count', $source->usage_count);

            // Delete source tag
            $source->delete();

            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            return false;
        }
    }

    public function getTagCloud(): array
    {
        $tags = $this->model->all();
        $maxCount = $tags->max('usage_count');
        $minCount = $tags->min('usage_count');
        $spread = $maxCount - $minCount;

        return $tags->map(function ($tag) use ($spread, $minCount) {
            $weight = $spread > 0 
                ? ($tag->usage_count - $minCount) / $spread 
                : 0.5;
                
            return [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'count' => $tag->usage_count,
                'weight' => $weight
            ];
        })->all();
    }

    public function getRelatedTags(int $tagId, int $limit = 5): Collection
    {
        return $this->model->select('tags.*')
            ->join('content_tag as ct1', 'tags.id', '=', 'ct1.tag_id')
            ->join('content_tag as ct2', 'ct1.content_id', '=', 'ct2.content_id')
            ->where('ct2.tag_id', $tagId)
            ->where('tags.id', '!=', $tagId)
            ->groupBy('tags.id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->get();
    }

    public function searchTags(string $query): Collection
    {
        return $this->model->where('name', 'like', "%{$query}%")
            ->orWhere('slug', 'like', "%{$query}%")
            ->orderBy('usage_count', 'desc')
            ->get();
    }

    public function updateUsageCount(int $tagId): void
    {
        $count = \DB::table('content_tag')
            ->where('tag_id', $tagId)
            ->count();

        $this->model->where('id', $tagId)
            ->update(['usage_count' => $count]);
    }

    protected function updateTagsUsageCount(array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            $this->updateUsageCount($tagId);
        }
    }
}
