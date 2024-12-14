<?php

namespace App\Core\Tag\Http\Controllers;

use App\Core\Tag\Services\TagService;
use App\Core\Tag\Http\Requests\{CreateTagRequest, UpdateTagRequest};
use App\Core\Tag\Http\Resources\TagResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Http\Resources\Json\ResourceCollection;

class TagController extends Controller
{
    /**
     * @var TagService
     */
    protected TagService $tagService;

    /**
     * @param TagService $tagService
     */
    public function __construct(TagService $tagService)
    {
        $this->tagService = $tagService;
    }

    /**
     * Display a listing of tags.
     *
     * @param Request $request
     * @return ResourceCollection
     */
    public function index(Request $request): ResourceCollection
    {
        $tags = $this->tagService->getPaginatedTags(
            $request->get('per_page', 15),
            $request->get('search')
        );

        return TagResource::collection($tags);
    }

    /**
     * Store a newly created tag.
     *
     * @param CreateTagRequest $request
     * @return JsonResponse
     */
    public function store(CreateTagRequest $request): JsonResponse
    {
        $tag = $this->tagService->create($request->validated());

        return response()->json(new TagResource($tag), 201);
    }

    /**
     * Display the specified tag.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $tag = $this->tagService->find($id);

        return response()->json(new TagResource($tag));
    }

    /**
     * Update the specified tag.
     *
     * @param UpdateTagRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateTagRequest $request, int $id): JsonResponse
    {
        $tag = $this->tagService->update($id, $request->validated());

        return response()->json(new TagResource($tag));
    }

    /**
     * Remove the specified tag.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $this->tagService->delete($id);

        return response()->json(null, 204);
    }

    /**
     * Get popular tags.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function popular(Request $request): JsonResponse
    {
        $tags = $this->tagService->getPopularTags(
            $request->get('limit', 10)
        );

        return response()->json(TagResource::collection($tags));
    }

    /**
     * Merge two tags.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function merge(Request $request): JsonResponse
    {
        $sourceId = $request->input('source_id');
        $targetId = $request->input('target_id');

        $tag = $this->tagService->mergeTags($sourceId, $targetId);

        return response()->json(new TagResource($tag));
    }
}
