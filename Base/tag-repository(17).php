<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagRepository implements TagRepositoryInterface
{
    protected $model;

    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->findOrFail($id);
    }

    public function findBySlug(string $slug)
    {
        return $this->model->where('slug', $slug)->firstOrFail();
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model
            ->when(isset($filters['search']), function ($query) use ($filters) {
                return $query->where('name', 'like', "%{$filters['search']}%");
            })
            ->when(isset($filters['type']), function ($query) use ($filters) {
                return $query->where('type', $filters['type']);
            })
            ->orderBy('name')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            if (!isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }
            return $this->model->create($data);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $tag = $this->find($id);
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }
            $tag->update($data);
            return $tag->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $tag = $this->find($id);
            $tag->contents()->detach();
            return $tag->delete();
        });
    }

    public function findOrCreateMany(array $tags): Collection
    {
        return DB::transaction(function () use ($tags) {
            $result = collect();

            foreach ($tags as $tagData) {
                if (is_array($tagData)) {
                    $name = $tagData['name'];
                    $attributes = $tagData;
                } else {
                    $name = $tagData;
                    $attributes = ['name' => $name, 'slug' => Str::slug($name)];
                }

                $tag = $this->model->firstOrCreate(
                    ['name' => $name],
                    $attributes
                );

                $result->push($tag);
            }

            return $result;
        });
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->model
            ->withCount('contents')
            ->orderByDesc('contents_count')
            ->limit($limit)
            ->get();
    }

    public function getRelated(int $tagId, int $limit = 5): Collection
    {
        $tag = $this->find($tagId);

        return $this->model
            ->whereHas('contents', function ($query) use ($tag) {
                $query->whereIn('content_id', $tag->contents->pluck('id'));
            })
            ->where('id', '!=', $tagId)
            ->withCount(['contents' => function ($query) use ($tag) {
                $query->whereIn('content_id', $tag->contents->pluck('id'));
            }])
            ->orderByDesc('contents_count')
            ->limit($limit)
            ->get();
    }
}
