<?php

namespace App\Core\Media\Http\Controllers;

use App\Core\Media\Services\MediaHandlerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\{Request, JsonResponse};
use App\Core\Media\Http\Requests\{
    UploadMediaRequest,
    UpdateMediaRequest,
    BulkMediaRequest
};

class MediaController extends Controller
{
    public function __construct(private MediaHandlerService $mediaHandler)
    {
        $this->middleware('auth:api');
        $this->authorizeResource(Media::class);
    }

    public function index(Request $request): JsonResponse
    {
        $media = $this->mediaHandler->handleList($request->all());
        return response()->json($media);
    }

    public function store(UploadMediaRequest $request): JsonResponse
    {
        $media = $this->mediaHandler->handleUpload(
            $request->file('file'),
            $request->validated()
        );
        return response()->json($media, 201);
    }

    public function show(int $id): JsonResponse
    {
        $media = $this->mediaHandler->handleShow($id);
        return response()->json($media);
    }

    public function update(UpdateMediaRequest $request, int $id): JsonResponse
    {
        $media = $this->mediaHandler->handleUpdate($id, $request->validated());
        return response()->json($media);
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->mediaHandler->handleDelete($id);
        return response()->json(['success' => $result]);
    }

    public function bulkAction(BulkMediaRequest $request): JsonResponse
    {
        $result = $this->mediaHandler->handleBulkOperation(
            $request->input('action'),
            $request->input('media_ids'),
            $request->input('data', [])
        );
        return response()->json($result);
    }
}

class MediaVariantController extends Controller
{
    public function __construct(private MediaHandlerService $mediaHandler)
    {
        $this->middleware('auth:api');
    }

    public function regenerate(int $mediaId, string $variant): JsonResponse
    {
        $this->authorize('update', Media::findOrFail($mediaId));
        
        $result = $this->mediaHandler->regenerateVariant($mediaId, $variant);
        return response()->json($result);
    }

    public function delete(int $mediaId, string $variant): JsonResponse
    {
        $this->authorize('update', Media::findOrFail($mediaId));
        
        $result = $this->mediaHandler->deleteVariant($mediaId, $variant);
        return response()->json(['success' => $result]);
    }
}

class MediaTypeController extends Controller
{
    public function __construct(private MediaHandlerService $mediaHandler)
    {
        $this->middleware('auth:api');
    }

    public function index(string $type, Request $request): JsonResponse
    {
        $media = $this->mediaHandler->handleTypeList($type, $request->all());
        return response()->json($media);
    }

    public function stats(string $type): JsonResponse
    {
        $stats = $this->mediaHandler->getTypeStats($type);
        return response()->json($stats);
    }
}

class MediaBatchController extends Controller
{
    public function __construct(private MediaHandlerService $mediaHandler)
    {
        $this->middleware('auth:api');
    }

    public function upload(Request $request): JsonResponse
    {
        $this->authorize('create', Media::class);
        
        $files = $request->file('files');
        $options = $request->input('options', []);
        
        $results = $this->mediaHandler->handleBatchUpload($files, $options);
        return response()->json($results);
    }

    public function download(Request $request): JsonResponse
    {
        $mediaIds = $request->input('media_ids');
        $archive = $this->mediaHandler->handleBatchDownload($mediaIds);
        
        return response()->json(['download_url' => $archive->url]);
    }
}
