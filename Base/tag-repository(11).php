<?php

namespace App\Core\Repositories;

use App\Core\Models\Tag;
use App\Core\Exceptions\TagNotFoundException;
use App\Core\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class TagRepository implements TagRepositoryInterface
{
    public function __construct(
        private Tag $model
    ) {}

    public function findById(int $id): ?Tag
    {
        try {
            return $this->model->findOrFail($id);
        } catch (ModelNotFoundException) {
            throw new TagNotFoundException("Tag with ID {$id} not found");
        }
    }

    public function findBySlug(string $slug): ?Tag
    {
        try {
            return $this->model->where('slug', $slug)->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new TagNotFoundException("Tag with slug {$slug} not found");
        }
    }

    public function findByName(string $name): ?Tag
    {
        return $this->model->where('name', $name)->first();
    }

    public function getAll(): Collection
    {
        return $this->model->withCount('content')
            ->orderBy('content_count', 'desc')
            ->get();
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->model->withCount('content')
            ->having('content_count', '>', 0)
            ->orderBy('content_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public function store(array $data): Tag
    {
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Tag
    {
        $tag = $this->findById($id);

        if (empty($data['slug']) && isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $tag->update($data);
        return $tag->fresh();
    }

    public function delete(int $id): bool
    {
        return (bool) $this->findById($id)->delete();
    }

    public function syncTags(array $tags): Collection
    {
        $result = collect();

        foreach ($tags as $tagName) {
            $tag = $this->findByName($tagName) ?? $this->store(['name' => $tagName]);
            $result->push($tag);
        }

        return $result;
    }

    public function getRelated(int $tagId, int $limit = 5): Collection
    {
        $tag = $this->findById($tagId);

        return $this->model->whereHas('content', function ($query) use ($tag) {
            $query->whereIn('content_id', $tag->content->pluck('id'));
        })
        ->where('id', '!=', $tagId)
        ->withCount('content')
        ->orderBy('content_count', 'desc')
        ->limit($limit)
        ->get();
    }
}
