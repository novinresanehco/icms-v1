<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TagRepository implements TagRepositoryInterface
{
    protected Tag $model;
    protected int $cacheTTL = 3600;

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
                'slug' => $data['slug'] ?? str($data['name'])->slug(),
                'description' => $data['description'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'status' => $data['status'] ?? 'active',
                'type' => $data['type'] ?? 'general',
            ]);

            $this->clearTagCache();
            DB::commit();

            return $tag->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create tag: ' . $e->getMessage());
            return null;
        }
    }

    public function createMultiple(array $tags): array
    {
        $createdIds = [];

        try {
            DB::beginTransaction();

            foreach ($tags as $tagData) {
                if (is_string($tagData)) {
                    $tagData = ['name' => $tagData];
                }

                $id = $this->create($tagData);
                if ($id) {
                    $createdIds[] = $id;
                }
            }

            DB::commit();
            return $createdIds;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create multiple tags: ' . $e->getMessage());
            return [];
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();

            $tag = $this->model->findOrFail($id);
            
            $updateData = [
                'name' => $data['name'] ?? $tag->name,
                'slug' => $data['slug'] ?? ($data['name'] ? str($data['name'])->slug() : $tag->slug),
                'description' => $data['description'] ?? $tag->description,
                'metadata' => array_merge($tag->metadata ?? [], $data['metadata'] ?? []),
                'status' => $data['status'] ?? $tag->status,
                'type' => $data['type'] ?? $tag->type,
            ];

            $tag->update($updateData);

            $this->clearTagCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update tag: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $tag = $this->model->findOrFail($id);
            
            // Detach from all content
            $tag->contents()->detach();
            
            $tag->delete();

            $this->clearTagCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete tag: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $id): ?array
    {
        try {
            return Cache::remember(
                "tag.{$id}",
                $this->cacheTTL,
                fn() => $this->model->findOrFail($id)->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get tag: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query();

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (!empty($filters['search'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('name', 'LIKE', "%{$filters['search']}%")
                        ->orWhere('description', 'LIKE', "%{$filters['search']}%");
                });
            }

            if (!empty($filters['min_usage'])) {
                $query->has('contents', '>=', $filters['min_usage']);
            }

            $orderBy = $filters['order_by'] ?? 'name';
            $orderDir = $filters['order_dir'] ?? 'asc';
            $query->orderBy($orderBy, $orderDir);

            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated tags: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getBySlug(string $slug): ?array
    {
        try {
            return Cache::remember(
                "tag.slug.{$slug}",
                $this->cacheTTL,
                fn() => $this->model->where('slug', $slug)
                    ->firstOrFail()
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get tag by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function getPopular(int $limit = 10): Collection
    {
        try {
            return Cache::remember(
                "tags.popular.{$limit}",
                $this->cacheTTL,
                fn() => $this->model->withCount('contents')
                    ->orderBy('contents_count', 'desc')
                    ->limit($limit)
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get popular tags: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getOrCreate(string $name): ?int
    {
        try {
            DB::beginTransaction();

            $tag = $this->model->firstOrCreate(
                ['name' => $name],
                ['slug' => str($name)->slug()]
            );

            DB::commit();
            return $tag->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to get or create tag: ' . $e->getMessage());
            return null;
        }
    }

    public function mergeTags(int $sourceId, int $targetId): bool
    {
        try {
            DB::beginTransaction();

            $source = $this->model->findOrFail($sourceId);
            $target = $this->model->findOrFail($targetId);

            // Move all content associations
            DB::table('content_tag')
                ->where('tag_id', $sourceId)
                ->update(['tag_id' => $targetId]);

            // Delete source tag
            $source->delete();

            $this->clearTagCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to merge tags: ' . $e->getMessage());
            return false;
        }
    }

    protected function clearTagCache(): void
    {
        Cache::tags(['tags'])->flush();
    }
}
