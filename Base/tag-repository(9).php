<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\TagRepositoryInterface;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagRepository implements TagRepositoryInterface
{
    protected Tag $model;

    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Tag
    {
        return $this->model->find($id);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->withCount('contents')
            ->orderBy('contents_count', 'desc')
            ->paginate($perPage);
    }

    public function create(array $data): Tag
    {
        DB::beginTransaction();
        try {
            $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
            
            $tag = $this->model->create($data);
            
            if (!empty($data['meta'])) {
                $tag->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Tag
    {
        DB::beginTransaction();
        try {
            $tag = $this->model->findOrFail($id);
            
            if (!empty($data['name']) && $tag->name !== $data['name']) {
                $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
            }
            
            $tag->update($data);
            
            if (isset($data['meta'])) {
                $tag->meta()->delete();
                $tag->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $tag = $this->model->findOrFail($id);
            $tag->meta()->delete();
            $tag->contents()->detach();
            $tag->delete();
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function findOrCreate(string $name): Tag
    {
        $slug = Str::slug($name);
        
        $tag = $this->model->where('slug', $slug)->first();
        
        if (!$tag) {
            $tag = $this->create([
                'name' => $name,
                'slug' => $slug
            ]);
        }
        
        return $tag;
    }

    public function syncContentTags(int $contentId, array $tagIds): bool
    {
        try {
            DB::table('content_tag')->where('content_id', $contentId)->delete();
            
            $data = array_map(function($tagId) use ($contentId) {
                return [
                    'content_id' => $contentId,
                    'tag_id' => $tagId,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }, $tagIds);
            
            return DB::table('content_tag')->insert($data);
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->model->withCount('contents')
            ->orderBy('contents_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public function search(string $term): Collection
    {
        return $this->model->where('name', 'LIKE', "%{$term}%")
            ->orWhere('slug', 'LIKE', "%{$term}%")
            ->get();
    }

    public function getRelated(int $tagId, int $limit = 5): Collection
    {
        return $this->model->whereHas('contents', function($query) use ($tagId) {
            $query->whereHas('tags', function($q) use ($tagId) {
                $q->where('tags.id', $tagId);
            });
        })
        ->where('id', '!=', $tagId)
        ->withCount('contents')
        ->orderBy('contents_count', 'desc')
        ->limit($limit)
        ->get();
    }

    public function getByType(string $type): Collection
    {
        return $this->model->where('type', $type)
            ->withCount('contents')
            ->orderBy('contents_count', 'desc')
            ->get();
    }
}
