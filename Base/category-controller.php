<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Services\CategoryService;
use App\Core\Http\Requests\Categories\StoreCategoryRequest;
use App\Core\Http\Requests\Categories\UpdateCategoryRequest;
use App\Core\Http\Resources\CategoryResource;
use App\Core\Http\Resources\CategoryCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CategoryController extends ApiController
{
    protected CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function index(): CategoryCollection
    {
        $categories = $this->categoryService->getAllCategories();
        return new CategoryCollection($categories);
    }

    public function hierarchy(): JsonResponse
    {
        $hierarchy = $this->categoryService->getCategoryHierarchy();
        return response()->json(['data' => $hierarchy]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->validated());
        return response()->json(
            new CategoryResource($category),
            Response::HTTP_CREATED
        );
    }

    public function show(int $id): CategoryResource
    {
        $category = $this->categoryService->getCategory($id);
        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, int $id): CategoryResource
    {
        $category = $this->categoryService->updateCategory($id, $request->validated());
        return new CategoryResource($category);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->categoryService->deleteCategory($id);
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
