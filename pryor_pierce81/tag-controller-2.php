<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\TagService;
use App\Http\Resources\TagResource;
use App\Core\Exceptions\TagException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    protected TagService $tagService;

    /**
     * TagController constructor.
     *
     * @param TagService $tagService
     */
    public function __construct(TagService $tagService)
    {
        $this->tagService = $tagService;
    }

    /**
     * Display a listing of tags
     *
     * @return AnonymousResourceCollection
     */
    public function index(): AnonymousResourceCollection
    {
        $tags = $this->tagService->getAllTags();
        return TagResource::collection($tags);
    }

    /**
     * Store a newly created tag
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $tag = $this->tagService->createTag($request->all());
            return response()->json([
                'message' => 'Tag created successfully',
                'data' => $tag
            ], 201);
        } catch (TagException $e) {
            return response()->json([
                'message' => 'Error creating tag',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Display the specified tag
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $tag = $this->tagService->getTagById($id);
            return response()->json([
                'data' => new TagResource($tag)
            ]);
        } catch (TagException $e) {
            return response()->json([
                'message' => 'Tag not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified tag
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $tag = $this->tagService->updateTag($id, $request->all());
            return response()->json([
                'message' => 'Tag updated successfully',
                'data' => $tag
            ]);
        } catch (TagException $e) {
            return response()->json([
                'message' => 'Error updating tag',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Remove the specified tag
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->tagService->deleteTag($id);
            return response()->json([
                'message' => 'Tag deleted successfully'
            ]);
        } catch (TagException $e) {
            return response()->json([
                'message' => 'Error deleting tag',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get popular tags
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function popular(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $tags = $this->tagService->getPopularTags($limit);
        
        return response()->json([
            'data' => TagResource::collection($tags)
        ]);
    }

    /**
     * Get related tags
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function related(int $id, Request $request): JsonResponse
    {
        try {
            $tag = $this->tagService->getTagById($id);
            $limit = $request->input('limit', 5);
            $relatedTags = $this->tagService->getRelatedTags($tag, $limit);
            
            return response()->json([
                'data' => TagResource::collection($relatedTags)
            ]);
        } catch (TagException $e) {
            return response()->json([
                'message' => 'Error fetching related tags',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
