<?php

namespace App\Repositories;

use App\Models\Content;
use App\Models\ContentRevision;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ContentRepository implements ContentRepositoryInterface
{
    protected $model;
    protected $revisionModel;

    public function __construct(Content $model, ContentRevision $revisionModel)
    {
        $this->model = $model;
        $this->revisionModel = $revisionModel;
    }

    public function find(int $id)
    {
        return $this->model->with(['category', 'tags', 'author'])->findOrFail($id);
    }

    public function findBySlug(string $slug)
    {
        return $this->model->with(['category', 'tags', 'author'])
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function getAll(array $filters = [], array $relations = []): LengthAwarePaginator
    {
        $query = $this->model->with(array_merge(['category', 'tags', 'author'], $relations));

        $query = $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $content = $this->model->create($data);

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            $this->createRevision($content);

            return $content->fresh(['category', 'tags', 'author']);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $content = $this->find($id);
            $content->update($data);

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            $this->createRevision($content);

            return $content->fresh(['category', 'tags', 'author']);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $content = $this->find($id);
            $content->tags()->detach();
            return $content->delete();
        });
    }

    public function restore(int $id)
    {
        return $this->model->withTrashed()->findOrFail($id)->restore();
    }

    public function getByCategory(int $categoryId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['category', 'tags', 'author'])
            ->where('category_id', $categoryId);

        $query = $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getByTag(string $tag, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['category', 'tags', 'author'])
            ->whereHas('tags', function ($query) use ($tag) {
                $query->where('name', $tag);
            });

        $query = $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function search(string $term, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['category', 'tags', 'author'])
            ->where(function ($query) use ($term) {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhere('content', 'like', "%{$term}%")
                    ->orWhere('excerpt', 'like', "%{$term}%");
            });

        $query = $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getRevisions(int $contentId): Collection
    {
        return $this->revisionModel
            ->where('content_id', $contentId)
            ->with('author')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function revertToRevision(int $contentId, int $revisionId)
    {
        return DB::transaction(function () use ($contentId, $revisionId) {
            $content = $this->find($contentId);
            $revision = $this->revisionModel->findOrFail($revisionId);

            $content->update([
                'title' => $revision->title,
                'content' => $revision->content,
                'excerpt' => $revision->excerpt,
                'metadata' => $revision->metadata
            ]);

            $this->createRevision($content, 'Reverted to revision #' . $revisionId);

            return $content->fresh(['category', 'tags', 'author']);
        });
    }

    protected function createRevision(Content $content, string $notes = ''): ContentRevision
    {
        return $this->revisionModel->create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'excerpt' => $content->excerpt,
            'metadata' => $content->metadata,
            'author_id' => auth()->id(),
            'notes' => $notes
        ]);
    }

    protected function applyFilters($query, array $filters)
    {
        return $query->when(isset($filters['status']), function ($query) use ($filters) {
                return $query->where('status', $filters['status']);
            })
            ->when(isset($filters['author_id']), function ($query) use ($filters) {
                return $query->where('author_id', $filters['author_id']);
            })
            ->when(isset($filters['published']), function ($query) use ($filters) {
                return $filters['published'] 
                    ? $query->published()
                    : $query->draft();
            })
            ->when(isset($filters['featured']), function ($query) use ($filters) {
                return $query->where('is_featured', $filters['featured']);
            })
            ->when(isset($filters['date_from']), function ($query) use ($filters) {
                return $query->where('created_at', '>=', $filters['date_from']);
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters) {
                return $query->where('created_at', '<=', $filters['date_to']);
            });
    }
}
