<?php

namespace App\Core\Http\Controllers;

use App\Core\Services\CategoryService;
use App\Core\Http\Requests\{CategoryCreateRequest, CategoryUpdateRequest};
use App\Core\Http\Resources\CategoryResource;
use Illuminate\Http\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    protected CategoryService $service;

    public function __construct(CategoryService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): JsonResponse
    {
        $categories = $request->input('tree') ? 
            $this->service->getTree() :
            $this->service->paginate($request->input('per_page', 15));

        return response()->json(
            CategoryResource::collection($categories),
            Response::HTTP_OK
        );
    }

    public function store(CategoryCreateRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated());

        return response()->json(
            new CategoryResource($category),
            Response::HTTP_CREATED
        );
    }

    public function show(int $id): JsonResponse
    {
        $category = $this->service->findById($id);

        return response()->json(
            new CategoryResource($category),
            Response::HTTP_OK
        );
    }

    public function update(CategoryUpdateRequest $request, int $id): JsonResponse
    {
        $category = $this->service->update($id, $request->validated());

        return response()->json(
            new CategoryResource($category),
            Response::HTTP_OK
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'order' => 'required|array',
            'order.*' => 'required|integer|exists:categories,id'
        ]);

        $this->service->reorder($request->input('order'));

        return response()->json(['message' => 'Categories reordered successfully']);
    }
}
