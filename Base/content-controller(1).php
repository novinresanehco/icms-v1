<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ContentController extends Controller
{
    public function __construct(
        protected ContentService $contentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $contents = $this->contentService->paginate($request->input('per_page', 15));
            return response()->json(['data' => $contents]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch contents: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch contents'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $content = $this->contentService->create($request->all());
            return response()->json(['data' => $content], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Failed to create content: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $content = $this->contentService->find($id);
            if (!$content) {
                return response()->json(['error' => 'Content not found'], Response::HTTP_NOT_FOUND);
            }
            return response()->json(['data' => $content]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch content: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $content = $this->contentService->update($id, $request->all());
            return response()->json(['data' => $content]);
        } catch (\Exception $e) {
            Log::error('Failed to update content: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->contentService->delete($id);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            Log::error('Failed to delete content: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $contents = $this->contentService->search($request->input('query', ''));
            return response()->json(['data' => $contents]);
        } catch (\Exception $e) {
            Log::error('Failed to search contents: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getVersions(int $id): JsonResponse
    {
        try {
            $versions = $this->contentService->getVersions($id);
            return response()->json(['data' => $versions]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch content versions: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function revertToVersion(int $id, int $versionId): JsonResponse
    {
        try {
            $content = $this->contentService->revertToVersion($id, $versionId);
            return response()->json(['data' => $content]);
        } catch (\Exception $e) {
            Log::error('Failed to revert content version: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
