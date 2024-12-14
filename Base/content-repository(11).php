<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\ContentRepositoryInterface;
use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentRepository implements ContentRepositoryInterface
{
    /**
     * @var Content
     */
    protected Content $model;

    /**
     * Constructor
     *
     * @param Content $model
     */
    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    /**
     * @inheritDoc
     */
    public function find(int $id): ?Content
    {
        return $this->model->find($id);
    }

    /**
     * @inheritDoc
     */
    public function findBySlug(string $slug): ?Content
    {
        return $this->model
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->query();

        // Apply filters
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        // Apply sorting
        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        return $query->paginate($perPage);
    }

    /**
     * @inheritDoc
     */
    public function create(array $data): Content
    {
        DB::beginTransaction();

        try {
            // Generate slug if not provided
            if (!isset($data['slug'])) {
                $data['slug'] = Str::slug($data['title']);
            }

            $content = $this->model->create($data);

            // Handle relationships
            if (!empty($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            if (!empty($data['meta'])) {
                $content->meta()->createMany($data['meta']);
            }

            DB::commit();
            return $content;
        } catch (QueryException $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();

        try {
            $content = $this->model->findOrFail($id);

            // Update slug if title changed
            if (isset($data['title']) && $content->title !== $data['title']) {
                $data['slug'] = Str::slug($data['title']);
            }

            $content->update($data);

            // Update relationships
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            if (isset($data['meta'])) {
                $content->meta()->delete();
                $content->meta()->createMany($data['meta']);
            }

            DB::commit();
            return $content;
        } catch (QueryException $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(int $id): bool
    {
        DB::beginTransaction();

        try {
            $content = $this->model->findOrFail($id);
            
            // Delete related data
            $content->tags()->detach();
            $content->meta()->delete();
            $content->delete();

            DB::commit();
            return true;
        } catch (QueryException $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function getByType(string $type, int $limit = 10): Collection
    {
        return $this->model
            ->where('type', $type)
            ->where('status', 'published')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function getByCategory(int $categoryId, int $limit = 10): Collection
    {
        return $this->model
            ->where('category_id', $categoryId)
            ->where('status', 'published')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, array $options = []): Collection
    {
        $searchQuery = $this->model
            ->where('status', 'published')
            ->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('content', 'LIKE', "%{$query}%")
                  ->orWhere('excerpt', 'LIKE', "%{$query}%");
            });

        // Apply search options
        if (!empty($options['type'])) {
            $searchQuery->where('type', $options['type']);
        }

        if (!empty($options['category_id'])) {
            $searchQuery->where('category_id', $options['category_id']);
        }

        $limit = $options['limit'] ?? 10;

        return $searchQuery->limit($limit)->get();
    }

    /**
     * @inheritDoc
     */
    public function getRelated(int $contentId, int $limit = 5): Collection
    {
        $content = $this->find($contentId);

        if (!$content) {
            return collect();
        }

        // Get related content based on tags and category
        return $this->model
            ->where('id', '!=', $contentId)
            ->where('status', 'published')
            ->where(function ($query) use ($content) {
                $query->where('category_id', $content->category_id)
                      ->orWhereHas('tags', function ($q) use ($content) {
                          $q->whereIn('id', $content->tags->pluck('id'));
                      });
            })
            ->limit($limit)
            ->get();
    }
}
