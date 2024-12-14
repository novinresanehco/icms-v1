<?php

namespace App\Core\Repositories;

use App\Models\Tag;
use Illuminate\Support\Collection;

class TagRepository extends AdvancedRepository
{
    protected $model = Tag::class;

    public function findOrCreate(string $name, string $type = 'default'): Tag
    {
        return $this->executeTransaction(function() use ($name, $type) {
            return $this->model->firstOrCreate([
                'name' => $name,
                'type' => $type
            ]);
        });
    }

    public function attachToModel(string $model, int $modelId, array $tags): void
    {
        $this->executeTransaction(function() use ($model, $modelId, $tags) {
            $tagIds = collect($tags)->map(function($tag) {
                return $this->findOrCreate($tag)->id;
            });

            $this->model->attachTags($model, $modelId, $tagIds);
        });
    }

    public function getForModel(string $model, int $modelId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($model, $modelId) {
            return $this->model
                ->whereHas('taggables', function($query) use ($model, $modelId) {
                    $query->where('taggable_type', $model)
                        ->where('taggable_id', $modelId);
                })
                ->get();
        }, $model, $modelId);
    }

    public function getPopular(int $limit = 10, string $type = null): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($limit, $type) {
            $query = $this->model
                ->withCount('taggables')
                ->orderBy('taggables_count', 'desc')
                ->limit($limit);

            if ($type) {
                $query->where('type', $type);
            }

            return $query->get();
        }, $limit, $type);
    }

    public function search(string $term): Collection
    {
        return $this->executeQuery(function() use ($term) {
            return $this->model
                ->where('name', 'LIKE', "%{$term}%")
                ->get();
        });
    }

    public function mergeTags(Tag $source, Tag $target): void
    {
        $this->executeTransaction(function() use ($source, $target) {
            $source->taggables()->update([
                'tag_id' => $target->id
            ]);
            
            $source->delete();
        });
    }
}
