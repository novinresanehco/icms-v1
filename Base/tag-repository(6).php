<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TagRepository implements TagRepositoryInterface
{
    protected Tag $model;
    
    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();
            
            $tag = $this->model->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
            ]);
            
            DB::commit();
            $this->clearTagCache();
            
            return $tag->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create tag: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $tagId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $tag = $this->model->findOrFail($tagId);
            $tag->update([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
            ]);
            
            DB::commit();
            $this->clearTagCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update tag: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $tagId): bool
    {
        try {
            DB::beginTransaction();
            
            $tag = $this->model->findOrFail($tagId);
            $tag->contents()->detach();
            $tag->delete();
            
            DB::commit();
            $this->clearTagCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete tag: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $tagId): ?array
    {
        try {
            $tag = $this->model->find($tagId);
            return $tag ? $tag->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get tag: ' . $e->getMessage());
            return null;
        }
    }

    public function getBySlug(string $slug): ?array
    {
        return Cache::remember("tag.{$slug}", 3600, function() use ($slug) {
            try {
                $tag = $this->model->where('slug', $slug)->first();
                return $tag ? $tag->toArray() : null;
            } catch (\Exception $e) {
                Log::error('Failed to get tag by slug: ' . $e->getMessage());
                return null;
            }
        });
    }

    public function getAll(): Collection
    {
        try {
            return $this->model->all();
        } catch (\Exception $e) {
            Log::error('Failed to get all tags: ' . $e->getMessage());
            return collect();
        }
    }

    public function findOrCreate(string $name): int
    {
        try {
            $tag = $this->model->firstOrCreate(
                ['name' => $name],
                ['slug' => Str::slug($name)]
            );
            
            return $tag->id;
        } catch (\Exception $e) {
            Log::error('Failed to find or create tag: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getPopular(int $limit = 10): Collection
    {
        return Cache::remember("tags.popular.{$limit}", 3600, function() use ($limit) {
            try {
                return $this->model->withCount('contents')
                    ->orderBy('contents_count', 'desc')
                    ->limit($limit)
                    ->get();
            } catch (\Exception $e) {
                Log::error('Failed to get popular tags: ' . $e->getMessage());
                return collect();
            }
        });
    }

    public function getRelated(int $tagId, int $limit = 5): Collection
    {
        try {
            $tag = $this->model->findOrFail($tagId);
            
            return $this->model->whereHas('contents', function($query) use ($tag) {
                    $query->whereIn('contents.id', $tag->contents->pluck('id'));
                })
                ->where('id', '!=', $tagId)
                ->withCount('contents')
                ->orderBy('contents_count', 'desc')
                ->limit($limit)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to get related tags: ' . $e->getMessage());
            return collect();
        }
    }

    protected function clearTagCache(): void
    {
        Cache::tags(['tags'])->flush();
    }
}
