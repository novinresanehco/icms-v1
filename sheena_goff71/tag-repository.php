<?php

namespace App\Core\Tag\Repositories;

use App\Core\Tag\Models\Tag;
use App\Core\Repository\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Exceptions\TagNotFoundException;

class TagRepository extends BaseRepository
{
    public function model(): string
    {
        return Tag::class;
    }

    public function create(array $data): Tag
    {
        return Tag::create($data);
    }

    public function update(Tag $tag, array $data): Tag
    {
        $tag->update($data);
        return $tag->fresh();
    }

    public function delete(Tag $tag): bool
    {
        return $tag->delete();
    }

    public function forceDelete(Tag $tag): bool
    {
        return $tag->forceDelete();
    }

    public function findOrFail(int $id): Tag
    {
        return Tag::findOrFail($id);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Tag::where('slug', $slug)->first();
    }

    public function getByIds(array $ids): Collection
    {
        return Tag::whereIn('id', $ids)->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = Tag::query();

        if (!empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%")
                 ->orWhere('description', 'like', "%{$filters['search']}%");
        }

        if (isset($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        }

        return $query->paginate($perPage);
    }

    public function getHierarchy(int $parentId = null, int $depth = null): Collection
    {
        $query = Tag::where('parent_id', $parentId);

        if ($depth !== null) {
            // Implementation for depth-limited hierarchy
            // Add depth-related logic here
        }

        return $query->with('children')->get();
    }

    public function getTagsWithUsageCount(): Collection
    {
        return Tag::withCount('content')->get();
    }

    public function findWithRelationships(int $id): Tag
    {
        return Tag::with(['content', 'children', 'parent'])->findOrFail($id);
    }
}
