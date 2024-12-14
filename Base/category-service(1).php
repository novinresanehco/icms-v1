<?php

namespace App\Core\Services;

use App\Core\Models\Category;
use App\Core\Services\Contracts\CategoryServiceInterface;
use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryService implements CategoryServiceInterface
{
    public function __construct(
        private CategoryRepositoryInterface $repository
    ) {}

    public function getCategory(int $id): ?Category
    {
        return Cache::tags(['categories'])->remember(
            "categories.{$id}",
            now()->addHour(),
            fn() => $this->repository->findById($id)
        );
    }

    public function getCategoryBySlug(string $slug): ?Category
    {
        return Cache::tags(['categories'])->remember(
            "categories.slug.{$slug}",
            now()->addHour(),
            fn() => $this->repository->findBySlug($slug)
        );
    }

    public function getAllCategories(): Collection
    {
        return Cache::tags(['categories'])->remember(
            'categories.all',
            now()->addHour(),
            fn() => $this->repository->getAll()
        );
    }

    public function getAllCategoriesPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getAllPaginated($perPage);
    }

    public function getParentCategories(): Collection
    {
        return Cache::tags(['categories'])->remember(
            'categories.parents',
            now()->addHour(),
            fn() => $this->repository->getParentCategories()
        );
    }

    public function getPopularCategories(int $limit = 10): Collection
    {
        return Cache::tags(['categories'])->remember(
            "categories.popular.{$limit}",
            now()->addHour(),
            fn() => $this->repository->getPopular($limit)
        );
    }

    public function getCategoryTree(): Collection
    {
        return Cache::tags(['categories'])->remember(
            'categories.tree',
            now()->addHour(),
            fn() => $this->repository->getWithChildren()
        );
    }

    public function createCategory(array $data): Category
    {
        $category = $this->repository->store($data);
        Cache::tags(['categories'])->flush();
        return $category;
    }

    public function updateCategory(int $id, array $data): Category
    {
        $category = $this->repository->update($id, $data);
        Cache::tags(['categories'])->flush();
        return $category;
    }

    public function deleteCategory(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['categories'])->flush();
        return $result;
    }

    public function restoreCategory(int $id): bool
    {
        $result = $this->repository->restore($id);
        Cache::tags(['categories'])->flush();
        return $result;
    }

    public function reorderCategories(array $data): bool
    {
        $result = $this->repository->reorder($data);
        Cache::tags(['categories'])->flush();
        return $result;
    }
}
