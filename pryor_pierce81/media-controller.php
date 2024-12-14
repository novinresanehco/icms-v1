<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\MediaService;
use App\Http\Resources\MediaResource;
use App\Core\Exceptions\MediaException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class MediaController extends Controller
{
    protected MediaService $mediaService;

    /**
     * MediaController constructor.
     *
     * @param MediaService $mediaService
     */
    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Display a listing of media
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $type = $request->input('type');
        $media = $type ? 
            $this->mediaService->getMediaByType($type) : 
            $this->mediaService->getAllMedia();
        
        return MediaResource::collection($media);
    }

    /**
     * Upload new media
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $attributes = $request->except('file');
            
            $media = $this->mediaService->uploadMedia($file, $attributes);
            
            return response()->json([
                'message' => 'Media uploaded successfully',
                'data' => $media
            ], 201);
        } catch (MediaException $e) {
            return response()->json([
                'message' => 'Error uploading media',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Display the specified media
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $media = $this->mediaService->getMediaById($id);
            return response()->json([
                'data' => new MediaResource($media)
            ]);
        } catch (MediaException $e) {
            return response()->json([
                'message' => 'Media not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified media
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $media = $this->mediaService->updateMedia($id, $request->all());
            return response()->json([
                'message' => 'Media updated successfully',
                'data' => new MediaResource($media)
            ]);
        } catch (MediaException $e) {
            return response()->json([
                'message' => 'Error updating media',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Remove the specified media
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->mediaService->deleteMedia($id);
            return response()->json([
                'message' => 'Media deleted successfully'
            ]);
        } catch (MediaException $e) {
            return response()->json([
                'message' => 'Error deleting media',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Attach media to content
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function attach(Request $request): JsonResponse
    {
        try {
            $this->mediaService->attachToContent(
                $request->input('content_id'),
                $request->input('media_ids'),
                $request->input('attributes', [])
            );
            
            return response()->json([
                'message' => 'Media attached successfully'
            ]);
        } catch (MediaException $e) {
            return response()->json([
                'message' => 'Error attaching media',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Clean unused media
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function clean(Request $request): JsonResponse
    {
        try {
            $days = $request->input('days', 30);
            $count = $this->mediaService->cleanUnusedMedia($days);
            
            return response()->json([
                'message' => "{$count} unused media files cleaned successfully"
            ]);
        } catch (MediaException $e) {
            return response()->json([
                'message' => 'Error cleaning unused media',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
