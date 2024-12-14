<?php

namespace App\Core\Tag\Http\Controllers;

use App\Core\Tag\Services\Integration\TagHandlerIntegration;
use App\Core\Tag\Http\Requests\{
    CreateTagRequest,
    UpdateTagRequest,
    BulkTagRequest
};
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    public function __construct(private TagHandlerIntegration $tagHandler)
    {
        $this->middleware('auth:api');
        $this->authorizeResource(Tag::class);
    }

    public function index(Request $request): JsonResponse
    {
        $tags = $this->tagHandler->handleIndex($request->all());
        return response()->json($tags);
    }

    public function store(CreateTagRequest $request): JsonResponse
    {
        $response = $this->tagHandler->handleCreate($request->validated());
        return response()->json($response, 201);
    }

    public function show(int $id): JsonResponse
    {
        $tag = $this->tagHandler->handleShow($id);
        return response()->json($tag);
    }

    public function update(UpdateTagRequest $request, int $id): JsonResponse
    {
        $response = $this->tagHandler->handleUpdate($id, $request->validated());
        return response()->json($response);
    }

    public function destroy(int $id): JsonResponse
    {
        $response = $this->tagHandler->handleDelete($id);
        return response()->json($response);
    }

    public function bulkAction(BulkTagRequest $request): JsonResponse
    {
        $response = $this->tagHandler->handleBulkOperation(
            $request->input('action'),
            $request->input('tag_ids'),
            $request->input('data', [])
        );
        return response()->json($response);
    }
}

class TagHierarchyController extends Controller
{
    public function __construct(private TagHandlerIntegration $tagHandler)
    {
        $this->middleware('auth:api');
    }

    public function index(?int $parentId = null): JsonResponse
    {
        $hierarchy = $this->tagHandler->handleHierarchy($parentId);
        return response()->json($hierarchy);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('reorder', Tag::class);
        $response = $this->tagHandler->handleReorder($request->input('order', []));
        return response()->json($response);
    }
}

class TagAttachmentController extends Controller
{
    public function __construct(private TagHandlerIntegration $tagHandler)
    {
        $this->middleware('auth:api');
    }

    public function attach(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorize('attachToContent', Tag::class);
        $response = $this->tagHandler->handleAttach($type, $id, $request->input('tag_ids', []));
        return response()->json($response);
    }

    public function detach(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorize('detachFromContent', Tag::class);
        $response = $this->tagHandler->handleDetach($type, $id, $request->input('tag_ids', []));
        return response()->json($response);
    }
}
