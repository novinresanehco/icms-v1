<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    protected array $searchableFields = ['name', 'slug'];
    protected array $filterableFields = ['type'];

    /**
     * Get popular tags with usage count
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopular(int $limit = 10): Collection
    {
        return $this->model
            ->select('tags.*', DB::raw('COUNT(content_tag.content_id) as usage_count'))
            ->leftJoin('content_tag', 'tags.id', '=', 'content_tag.tag_id')
            ->groupBy('tags.id')
            ->orderByDesc('usage_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Sync tags for content
     *
     * @param array $tags
     * @param int $contentId
     * @return void
     */
    public function syncContentTags(array $tags, int $contentId): void
    {
        $tagIds = [];
        foreach ($tags as $tagName) {
            $tag = $this->firstOrCreate(['name' => $tagName], ['slug' => \Str::slug($tagName)]);
            $tagIds[] = $tag->id;
        }
        
        DB::table('content_tag')
            ->where('content_id', $contentId)
            ->delete();
            
        $insertData = array_map(function($tagId) use ($contentId) {
            return [
                'content_id' => $contentId,
                'tag_id' => $tagId,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }, $tagIds);
        
        DB::table('content_tag')->insert($insertData);
    }

    /**
     * Get unused tags
     *
     * @return Collection
     */
    public function getUnused(): Collection
    {
        return $this->model
            ->select('tags.*')
            ->leftJoin('content_tag', 'tags.id', '=', 'content_tag.tag_id')
            ->whereNull('content_tag.content_id')
            ->get();
    }

    /**
     * Clean unused tags
     *
     * @return int
     */
    public function cleanUnused(): int
    {
        return $this->model
            ->leftJoin('content_tag', 'tags.id', '=', 'content_tag.tag_id')
            ->whereNull('content_tag.content_id')
            ->delete();
    }
}
