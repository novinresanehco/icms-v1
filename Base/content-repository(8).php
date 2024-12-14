<?php

namespace App\Repositories;

use App\Models\Content;
use App\Models\ContentRevision;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentRepository implements ContentRepositoryInterface
{
    protected Content $model;
    
    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();
            
            $content = $this->model->create($data);
            
            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            $this->createRevision($content, $data);
            
            DB::commit();
            $this->clearContentCache($content->slug);
            
            return $content->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create content: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $contentId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $content = $this->model->findOrFail($contentId);
            $content->update($data);
            
            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            $this->createRevision($content, $data);
            
            DB::commit();
            $this->clearContentCache($content->slug);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update content: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $contentId): bool
    {
        try {
            DB::beginTransaction();
            
            $content = $this->model->findOrFail($contentId);
            $content->categories()->detach();
            $content->tags()->detach();
            $content->delete();
            
            DB::commit();
            $this->clearContentCache($content->slug);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete content: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $contentId, array $relations = []): ?array
    {
        try {
            $content = $this->model->with($relations)->find($contentId);
            return $content ? $content->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get content: ' . $e->getMessage());
            return null;
        }
    }

    public function getBySlug(string $slug, array $relations = []): ?array
    {
        return Cache::remember("content.{$slug}", 3600, function() use ($slug, $relations) {
            try {
                $content = $this->model
                    ->where('slug', $slug)
                    ->where('status', true)
                    ->with($relations)
                    ->first();
                    
                return $content ? $content->toArray() : null;
            } catch (\Exception $e) {
                Log::error('Failed to get content by slug: ' . $e->getMessage());
                return null;
            }
        });
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query();
            
            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['author_id'])) {
                $query->where('author_id', $filters['author_id']);
            }
            
            if (isset($filters['category_id'])) {
                $query->whereHas('categories', function($q) use ($filters) {
                    $q->where('categories.id', $filters['category_id']);
                });
            }
            
            return $query->latest()->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated content: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getPublishedByType(string $type, int $limit = 10): Collection
    {
        try {
            return $this->model
                ->where('type', $type)
                ->where('status', true)
                ->whereNotNull('published_at')
                ->latest('published_at')
                ->limit($limit)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to get published content: ' . $e->getMessage());
            return collect();
        }
    }

    public function search(string $query, array $types = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $searchQuery = $this->model->query()
                ->where('status', true)
                ->where(function($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                      ->orWhere('content', 'like', "%{$query}%")
                      ->orWhere('excerpt', 'like', "%{$query}%");
                });
                
            if (!empty($types)) {
                $searchQuery->whereIn('type', $types);
            }
            
            return $searchQuery->latest()->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to search content: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function publishContent(int $contentId): bool
    {
        try {
            return $this->model->where('id', $contentId)->update([
                'status' => true,
                'published_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to publish content: ' . $e->getMessage());
            return false;
        }
    }

    public function unpublishContent(int $contentId): bool
    {
        try {
            return $this->model->where('id', $contentId)->update([
                'status' => false,
                'published_at' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to unpublish content: ' . $e->getMessage());
            return false;
        }
    }

    public function updateMetadata(int $contentId, array $metadata): bool
    {
        try {
            $content = $this->model->findOrFail($contentId);
            $content->metadata = array_merge($content->metadata ?? [], $metadata);
            return $content->save();
        } catch (\Exception $e) {
            Log::error('Failed to update content metadata: ' . $e->getMessage());
            return false;
        }
    }

    protected function createRevision(Content $content, array $data): void
    {
        ContentRevision::create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'metadata' => $content->metadata,
            'editor_id' => $data['editor_id'] ?? auth()->id(),
            'reason' => $data['revision_reason'] ?? 'Content update',
        ]);
    }

    protected function clearContentCache(string $slug): void
    {
        Cache::forget("content.{$slug}");
    }
}
