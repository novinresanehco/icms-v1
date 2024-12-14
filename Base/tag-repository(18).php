<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

interface TagRepositoryInterface extends RepositoryInterface
{
    public function findBySlug(string $slug): ?Tag;
    public function findOrCreateMany(array $names): Collection;
    public function getPopular(int $limit = 10): Collection;
    public function mergeTags(int $sourceId, int $targetId): void;
    public function syncTags(string $modelType, int $modelId, array $tagIds): void;
}

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\TagRepositoryInterface;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function findOrCreateMany(array $names): Collection
    {
        $tags = collect();
        
        foreach ($names as $name) {
            $tags->push($this->model->firstOrCreate([
                'name' => trim($name),
                'slug' => Str::slug(trim($name))
            ]));
        }
        
        return $tags;
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->model->withCount('taggables')
            ->orderByDesc('taggables_count')
            ->limit($limit)
            ->get();
    }

    public function mergeTags(int $sourceId, int $targetId): void
    {
        $source = $this->findOrFail($sourceId);
        $target = $this->findOrFail($targetId);
        
        // Move all taggable relationships
        \DB::transaction(function () use ($source, $target) {
            \DB::table('taggables')
                ->where('tag_id', $source->id)
                ->update(['tag_id' => $target->id]);
                
            $source->delete();
        });
    }

    public function syncTags(string $modelType, int $modelId, array $tagIds): void
    {
        \DB::table('taggables')
            ->where('taggable_type', $modelType)
            ->where('taggable_id', $modelId)
            ->delete();
            
        $records = array_map(function ($tagId) use ($modelType, $modelId) {
            return [
                'tag_id' => $tagId,
                'taggable_type' => $modelType,
                'taggable_id' => $modelId,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }, $tagIds);
        
        \DB::table('taggables')->insert($records);
    }
}
