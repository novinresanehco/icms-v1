<?php

namespace App\Services;

use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    protected CategoryRepositoryInterface $categoryRepository;
    
    public function __construct(CategoryRepositoryInterface $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }
    
    public function createCategory(array $data): ?int
    {
        $this->validateCategoryData($data);
        return $this->categoryRepository->create($data);
    }
    
    public function updateCategory(int $categoryId, array $data): bool
    {
        $this->validateCategoryData($data);
        return $this->categoryRepository->update($categoryId, $data);
    }
    
    public function deleteCategory(int $categoryId): bool
    {
        return $this->categoryRepository->delete($categoryId);
    }
    
    public function getCategory(int $categoryId): ?array
    {
        return $this->categoryRepository->get($categoryId);
    }
    
    public function getCategoryBySlug(string $slug): ?array
    {
        return $this->categoryRepository->getBySlug($slug);
    }
    
    public function getAllCategories(): Collection
    {
        return $this->categoryRepository->getAll();
    }
    
    public function getNestedCategories(): Collection
    {
        return $this->categoryRepository->getAllNested();
    }
    
    public function getCategoryChildren(int $categoryId): Collection
    {
        return $this->categoryRepository->getChildren($categoryId);
    }
    
    public function moveCategory(int $categoryId, ?int $parentId): bool
    {
        return $this->categoryRepository->moveNode($categoryId, $parentId);
    }
    
    public function reorderCategory(int $categoryId, int $position): bool
    {
        return $this->categoryRepository->reorder($categoryId, $position);
    }
    
    protected function validateCategoryData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
