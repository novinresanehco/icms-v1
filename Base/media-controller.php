<?php

namespace App\Core\Http\Controllers;

use App\Core\Services\Contracts\MediaServiceInterface;
use App\Core\Http\Requests\MediaUploadRequest;
use App\Core\Http\Requests\MetadataUpdateRequest;
use App\Core\Http\Resources\MediaResource;
use App\Core\Exceptions\MediaNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

class MediaController extends Controller
{
    public function __construct(
        private MediaServiceInterface $mediaService
    ) {}

    public function index(string $type = null): ResourceCollection
    {
        $media = $type 
            ? $this->mediaService->getAllByType($type)
            : $this->mediaService->getAll();

        return MediaResource::collection($media);
    }

    public function store(MediaUploadRequest $request): JsonResponse
    {
        $media = $this->mediaService->upload(
            $request->file('file'),
            $request->input('type')
        );

        return (new MediaResource($media))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(int $id): MediaResource
    {
        $media = $this->mediaService->getById($id);
        return new MediaResource($media);
    }

    public function updateMetadata(int $id, MetadataUpdateRequest $request): MediaResource
    {
        $media = $this->mediaService->updateMetadata($id, $request->validated());
        return new MediaResource($media);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->mediaService->deleteById($id);
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
