<?php

namespace App\Core\Repositories;

use App\Core\Models\Post;
use App\Core\Contracts\Repositories\PostRepositoryInterface;
use App\Core\Exceptions\PostNotFoundException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class PostRepository implements PostRepositoryInterface
{
    protected Post $model;
    
    public function __construct(Post $model)
    {
        $this->model = $model;
    }

    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $cacheKey = 'posts.paginated.' . md5(serialize($filters) . $perPage);
        
        return Cache::tags(['posts'])->remember(
            $cacheKey,
            config('cache.posts.ttl'),
            fn() => $this->model
                ->with(['category', 'author', 'tags'])
                ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
                ->when(isset($filters['category_id']), fn($q) => $q->where('category_id', $filters['category_id']))
                ->when(isset($filters['author_id']), fn($q) => $q->where('author_id', $filters['author_id']))
                ->orderBy('created_at', 'desc')
                ->paginate($perPage)
        );
    }

    public function findById(int $id, array $relations = []): Post
    {
        $cacheKey = "post.{$id}." . md5(serialize($relations));
        
        $post = Cache::tags(['posts'])->remember(
            $cacheKey,
            config('cache.posts.ttl'),
            fn() => $this->model->with($relations)->find($id)
        );

        if (!$post) {
            throw new PostNotFoundException("Post with ID {$id} not found");
        }

        return $post;
    }

    public function findBySlug(string $slug, array $relations = []): Post
    {
        $cacheKey = "post.slug.{$slug}." . md5(serialize($relations));
        
        $post = Cache::tags(['posts'])->remember(
            $cacheKey,
            config('cache.posts.ttl'),
            fn() => $this->model->with($relations)->where('slug', $slug)->first()
        );

        if (!$post) {
            throw new PostNotFoundException("Post with slug {$slug} not found");
        }

        return $post;
    }

    public function create(array $data): Post
    {
        DB::beginTransaction();
        try {
            $post = $this->model->create($data);
            
            if (isset($data['tags'])) {
                $post->tags()->sync($data['tags']);
            }
            
            $this->clearPostCache();
            
            DB::commit();
            return $post;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Post
    {
        DB::beginTransaction();
        try {
            $post = $this->findById($id);
            $post->update($data);
            
            if (isset($data['tags'])) {
                $post->tags()->sync($data['tags']);
            }
            
            $this->clearPostCache();
            
            DB::commit();
            return $post;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $post = $this->findById($id);
            $deleted = $post->delete();
            
            $this->clearPostCache();
            
            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        $cacheKey = "posts.category.{$categoryId}.{$perPage}";
        
        return Cache::tags(['posts'])->remember(
            $cacheKey,
            config('cache.posts.ttl'),
            fn() => $this->model
                ->with(['author', 'tags'])
                ->where('category_id', $categoryId)
                ->where('status', true)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage)
        );
    }

    public function getByTag(int $tagId, int $perPage = 15): LengthAwarePaginator
    {
        $cacheKey = "posts.tag.{$tagId}.{$perPage}";
        
        return Cache::tags(['posts'])->remember(
            $cacheKey,
            config('cache.posts.ttl'),
            fn() => $this->model
                ->with(['author', 'category'])
                ->whereHas('tags', fn($q) => $q->where('id', $tagId))
                ->where('status', true)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage)
        );
    }

    protected function clearPostCache(): void
    {
        Cache::tags(['posts'])->flush();
    }
}
