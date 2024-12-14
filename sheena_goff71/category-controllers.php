<?php

namespace App\Core\Category\Http\Controllers;

use App\Core\Category\Services\CategoryHandlerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\{Request, JsonResponse};
use App\Core\Category\Http\Requests\{
    CreateCategoryRequest,
    UpdateCategoryRequest,
    MoveCategoryRequest,
    BulkCategoryRequest
};

class CategoryController extends Controller
{
    public function __construct(private CategoryHandlerService $categoryHandler)
    {
        $this->middleware('auth:api');
        $this->authorizeResource(Category::class);
    }

    public function index(Request $request): JsonResponse
    {
        $categories = $this->categoryHandler->handleList($request->all());
        return response()->json($categories);
    }

    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryHandler->handleCreate($request->validated());
        return response()->json($category, 201);
    }

    public function show(int $id): JsonResponse
    {
        $category = $this->categoryHandler->handleShow($id);
        return response()->json($category);
    }

    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $category = $this->categoryHandler->handleUpdate($id, $request->validated());
        return response()->json($category);
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->categoryHandler->handleDelete($id);
        return response()->json(['success' => $result]);
    }

    public function bulkAction(BulkCategoryRequest $request): JsonResponse
    {
        $result = $this->categoryHandler->handleBulkOperation(
            $request->input('action'),
            $request->input('category_ids'),
            $request->input('data', [])
        );
        return response()->json($result);
    }
}

class CategoryTreeController extends Controller
{
    public function __construct(private CategoryHandlerService $categoryHandler)
    {
        $this->middleware('auth:api');
    }

    public function show(?int $parentId = null): JsonResponse
    {
        $tree = $this->categoryHandler->handleList(['parent_id' => $parentId]);
        return response()->json($tree);
    }

    public function move(MoveCategoryRequest $request, int $id): JsonResponse
    {
        $category = $this->categoryHandler->handleMove(
            $id,
            $request->input('parent_id'),
            $request->input('position', 0)
        );
        return response()->json($category);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('reorder', Category::class);
        
        $result = $this->categoryHandler->handleReorder($request->input('order', []));
        return response()->json($result);
    }
}

class CategoryAssignmentController extends Controller
{
    public function __construct(private CategoryHandlerService $categoryHandler)
    {
        $this->middleware('auth:api');
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $this->authorize('assignContent', Category::class);
        
        $result = $this->categoryHandler->handleContentAssignment(
            $id,
            $request->input('content_ids', [])
        );
        return response()->json($result);
    }

    public function unassign(Request $request, int $id): JsonResponse
    {
        $this->authorize('unassignContent', Category::class);
        
        $result = $this->categoryHandler->handleContentUnassignment(
            $id,
            $request->input('content_ids', [])
        );
        return response()->json($result);
    }
}

class CategoryStatsController extends Controller
{
    public function __construct(private CategoryHandlerService $categoryHandler)
    {
        $this->middleware('auth:api');
    }

    public function show(int $id): JsonResponse
    {
        $stats = $this->categoryHandler->handleStats($id);
        return response()->json($stats);
    }

    public function contentStats(int $id): JsonResponse
    {
        $stats = $this->categoryHandler->handleContentStats($id);
        return response()->json($stats);
    }
}
