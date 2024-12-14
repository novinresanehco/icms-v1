<?php

namespace App\Core\Repositories;

use App\Core\Models\Tag;
use App\Core\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;

class TagRepository implements TagRepositoryInterface
{
    public function __construct(
        private Tag $model
    ) {}

    public function syncTags(array $tagNames): Collection
    {
        $tags = collect();
        
        foreach ($tagNames as $name) {
            $slug = Str::slug($name);
            
            $tag = $this->model->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'slug' => $slug
                ]
            );
            
            $tags->push($tag);
        }
        
        return $tags;
    }

    public function getPopular(int $limit): Collection
    {
        return $this->model
            ->withCount('posts')
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get();
    }

    public function getRelated(int $tagId, int $limit): Collection
    {
        return $this->model
            ->whereHas('posts', function (Builder $query) use ($tagId) {
                $query->whereHas('tags', function (Builder $q) use ($tagId) {
                    $q->where('tags.id', $tagId);
                });
            })
            ->where('id', '!=', $tagId)
            ->withCount('posts')
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get();
    }

    public function store(array $data): Tag
    {
        $data['slug'] = Str::slug($data['name']);
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Tag
    {
        $tag = $this->model->findOrFail($id);
        
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }
        
        $tag->update($data);
        return $tag->fresh();
    }

    public function delete(int $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }
}
