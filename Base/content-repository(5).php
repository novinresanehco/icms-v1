<?php

namespace App\Repositories;

use App\Models\Content;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected array $searchableFields = ['title', 'content', 'slug', 'meta_description'];
    protected array $filterableFields = ['status', 'type', 'category_id', 'author_id'];
    protected array $relationships = ['category', 'author', 'tags', 'media'];

    public function __construct(Content $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Content
    {
        return Cache::remember(
            $this->getCacheKey("slug.{$slug}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)->where('slug', $slug)->first()
        );
    }

    public function getPublished(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('published'),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function createWithRelations(array $data): Content
    {
        try {
            DB::beginTransaction();

            // Create content
            $content = $this->create($data);

            // Attach tags if provided
            if (!empty($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            // Handle media attachments
            if (!empty($data['media'])) {
                $content->media()->sync($data['media']);
            }

            DB::commit();
            $this->clearModelCache();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to create content with relations: {$e->getMessage()}");
        }
    }

    public function updateWithRelations(int $id, array $data): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->update($id, $data);

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            if (isset($data['media'])) {
                $content->media()->sync($data['media']);
            }

            DB::commit();
            $this->clearModelCache();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update content with relations: {$e->getMessage()}");
        }
    }

    public function getByCategory(int $categoryId): Collection
    {
        return Cache::remember(
            $this->getCacheKey("category.{$categoryId}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('category_id', $categoryId)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function getByAuthor(int $authorId): Collection
    {
        return Cache::remember(
            $this->getCacheKey("author.{$authorId}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('author_id', $authorId)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }
}
