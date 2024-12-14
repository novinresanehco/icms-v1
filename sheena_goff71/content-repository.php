<?php

namespace App\Core\Content\Repositories;

use App\Core\Content\Models\Content;
use App\Core\Repository\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentRepository extends BaseRepository
{
    public function model(): string
    {
        return Content::class;
    }

    public function create(array $data): Content
    {
        return Content::create($data);
    }

    public function update(Content $content, array $data): Content
    {
        $content->update($data);
        return $content->fresh();
    }

    public function delete(Content $content): bool
    {
        return $content->delete();
    }

    public function findWithRelations(int $id): ?Content
    {
        return Content::with([
            'tags',
            'categories',
            'media',
            'author',
            'revisions'
        ])->find($id);
    }

    public function getByStatus(string $status, array $filters = []): Collection
    {
        $query = Content::where('status', $status);

        if (!empty($filters['category_id'])) {
            $query->whereHas('categories', function($q) use ($filters) {
                $q->where('categories.id', $filters['category_id']);
            });
        }

        if (!empty($filters['tag_id'])) {
            $query->whereHas('tags', function($q) use ($filters) {
                $q->where('tags.id', $filters['tag_id']);
            });
        }

        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        if (!empty($filters['type'])) {
            $query->where('content_type', $filters['type']);
        }

        return $query->with(['tags', 'categories', 'author'])->get();
    }

    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Content::query();

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('title', 'like', "%{$filters['search']}%")
                  ->orWhere('content', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category_ids'])) {
            $query->whereHas('categories', function($q) use ($filters) {
                $q->whereIn('categories.id', (array) $filters['category_ids']);
            });
        }

        if (!empty($filters['tag_ids'])) {
            $query->whereHas('tags', function($q) use ($filters) {
                $q->whereIn('tags.id', (array) $filters['tag_ids']);
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->with([
            'tags',
            'categories',
            'author'
        ])->orderBy(
            $filters['sort_by'] ?? 'created_at',
            $filters['sort_direction'] ?? 'desc'
        )->paginate($perPage);
    }

    public function getRelatedContent(Content $content, int $limit = 5): Collection
    {
        $tagIds = $content->tags->pluck('id')->toArray();
        $categoryIds = $content->categories->pluck('id')->toArray();

        return Content::where('id', '!=', $content->id)
            ->where('status', 'published')
            ->where(function($query) use ($tagIds, $categoryIds) {
                $query->whereHas('tags', function($q) use ($tagIds) {
                    $q->whereIn('tags.id', $tagIds);
                })->orWhereHas('categories', function($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            })
            ->with(['tags', 'categories'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function createRevision(Content $content): void
    {
        $revision = $content->replicate();
        $revision->parent_id = $content->id;
        $revision->revision_number = $content->revisions()->count() + 1;
        $revision->save();

        // Copy relationships
        $revision->tags()->sync($content->tags->pluck('id'));
        $revision->categories()->sync($content->categories->pluck('id'));
        $revision->media()->sync($content->media->pluck('id'));
    }

    public function revertToRevision(Content $content, int $revisionId): Content
    {
        $revision = Content::findOrFail($revisionId);
        
        if ($revision->parent_id !== $content->id) {
            throw new \InvalidArgumentException('Invalid revision');
        }

        $content->update($revision->toArray());
        
        // Sync relationships
        $content->tags()->sync($revision->tags->pluck('id'));
        $content->categories()->sync($revision->categories->pluck('id'));
        $content->media()->sync($revision->media->pluck('id'));

        return $content->fresh();
    }
}
