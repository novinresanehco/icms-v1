<?php

namespace App\Core\Repositories;

use App\Core\Models\Post;
use App\Core\Repositories\Contracts\PostRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PostRepository implements PostRepositoryInterface
{
    public function __construct(
        private Post $model
    ) {}

    public function findById(int $id): ?Post
    {
        return $this->model
            ->with(['author', 'categories', 'tags', 'media'])
            ->withCount(['comments', 'likes'])
            ->find($id);
    }

    public function findBySlug(string $slug): ?Post
    {
        return $this->model
            ->with(['author', 'categories', 'tags', 'media'])
            ->withCount(['comments', 'likes'])
            ->where('slug', $slug)
            ->first();
    }

    public function getLatest(int $limit = 10): Collection
    {
        return $this->model
            ->with(['author', 'categories'])
            ->withCount(['comments', 'likes'])
            ->published()
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->model
            ->with(['author', 'categories'])
            ->withCount(['comments', 'likes', 'views'])
            ->published()
            ->orderByDesc('views_count')
            ->orderByDesc('likes_count')
            ->orderByDesc('comments_count')
            ->limit($limit)
            ->get();
    }

    public function getFeatured(int $limit = 5): Collection
    {
        return $this->model
            ->with(['author', 'categories'])
            ->withCount(['comments', 'likes'])
            ->published()
            ->where('is_featured', true)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['author', 'categories', 'tags'])
            ->withCount(['comments', 'likes'])
            ->published()
            ->whereHas('categories', function (Builder $query) use ($categoryId) {
                $query->where('categories.id', $categoryId);
            })
            ->latest()
            ->paginate($perPage);
    }

    public function getByTag(int $tagId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['author', 'categories', 'tags'])
            ->withCount(['comments', 'likes'])
            ->published()
            ->whereHas('tags', function (Builder $query) use ($tagId) {
                $query->where('tags.id', $tagId);
            })
            ->latest()
            ->paginate($perPage);
    }

    public function getByAuthor(int $authorId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['author', 'categories', 'tags'])
            ->withCount(['comments', 'likes'])
            ->published()
            ->where('author_id', $authorId)
            ->latest()
            ->paginate($perPage);
    }

    public function search(string $query, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['author', 'categories', 'tags'])
            ->withCount(['comments', 'likes'])
            ->published()
            ->where(function (Builder $builder) use ($query) {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('content', 'like', "%{$query}%")
                    ->orWhereHas('tags', function (Builder $query) use ($query) {
                        $query->where('name', 'like', "%{$query}%");
                    })
                    ->orWhereHas('categories', function (Builder $query) use ($query) {
                        $query->where('name', 'like', "%{$query}%");
                    });
            })
            ->latest()
            ->paginate($perPage);
    }

    public function store(array $data): Post
    {
        $data['slug'] = Str::slug($data['title']);
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Post
    {
        $post = $this->model->findOrFail($id);
        
        if (isset($data['title'])) {
            $data['slug'] = Str::slug($data['title']);
        }
        
        $post->update($data);
        return $post->fresh();
    }

    public function delete(int $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }

    public function restore(int $id): bool
    {
        return $this->model->withTrashed()->findOrFail($id)->restore();
    }

    public function syncTags(Post $post, array $tagIds): void
    {
        $post->tags()->sync($tagIds);
    }

    public function syncCategories(Post $post, array $categoryIds): void
    {
        $post->categories()->sync($categoryIds);
    }
}
