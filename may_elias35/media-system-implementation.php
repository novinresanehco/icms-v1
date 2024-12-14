<?php

namespace App\Core\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $fillable = [
        'name',
        'file_name',
        'mime_type',
        'size',
        'path',
        'disk',
        'processing_status',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'processing_status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function contents(): MorphToMany
    {
        return $this->morphToMany(Content::class, 'mediable')
                    ->withTimestamps()
                    ->withPivot('order', 'type');
    }

    public function getUrl(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getFullPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }
}

namespace App\Core\Media\Services;

use App\Core\Media\Contracts\MediaProcessorInterface;
use App\Core\Media\Events\MediaProcessed;
use App\Core\Media\Exceptions\MediaProcessingException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class MediaProcessor implements MediaProcessorInterface
{
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'video/mp4'
    ];

    private array $imageVariants = [
        'thumb' => [200, 200],
        'medium' => [800, 800],
        'large' => [1600, 1600]
    ];

    public function process(UploadedFile $file): array
    {
        $this->validateFile($file);

        try {
            $originalPath = $this->storeOriginal($file);
            $metadata = $this->extractMetadata($file);
            
            $variants = [];
            if ($this->isImage($file)) {
                $variants = $this->processImageVariants($file);
            }

            event(new MediaProcessed($originalPath, $variants, $metadata));

            return [
                'original' => $originalPath,
                'variants' => $variants,
                'metadata' => $metadata
            ];
        } catch (\Exception $e) {
            throw new MediaProcessingException(
                "Failed to process media: {$e->getMessage()}"
            );
        }
    }

    public function generateThumbnail(string $path, int $width, int $height): string
    {
        try {
            $image = Image::make(Storage::path($path));
            
            $image->fit($width, $height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            $thumbnailPath = 'thumbnails/' . basename($path);
            Storage::put($thumbnailPath, $image->encode());

            return $thumbnailPath;
        } catch (\Exception $e) {
            throw new MediaProcessingException(
                "Failed to generate thumbnail: {$e->getMessage()}"
            );
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            throw new MediaProcessingException(
                "Unsupported file type: {$file->getMimeType()}"
            );
        }

        if ($file->getSize() > config('media.max_file_size')) {
            throw new MediaProcessingException(
                "File size exceeds limit"
            );
        }
    }

    private function storeOriginal(UploadedFile $file): string
    {
        $path = $file->store('media/original');
        
        if ($path === false) {
            throw new MediaProcessingException("Failed to store file");
        }

        return $path;
    }

    private function processImageVariants(UploadedFile $file): array
    {
        $variants = [];
        
        foreach ($this->imageVariants as $name => [$width, $height]) {
            $variants[$name] = $this->generateThumbnail(
                $file->path(),
                $width,
                $height
            );
        }

        return $variants;
    }

    private function extractMetadata(UploadedFile $file): array
    {
        return [
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
            'dimensions' => $this->isImage($file) 
                ? getimagesize($file->path()) 
                : null
        ];
    }

    private function isImage(UploadedFile $file): bool
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }
}

namespace App\Core\Media\Services;

use App\Core\Media\Contracts\MediaRepositoryInterface;
use App\Core\Media\Events\MediaCreated;
use App\Core\Media\Events\MediaDeleted;
use App\Core\Media\Exceptions\MediaException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MediaService
{
    private MediaRepositoryInterface $repository;
    private MediaProcessor $processor;

    public function __construct(
        MediaRepositoryInterface $repository,
        MediaProcessor $processor
    ) {
        $this->repository = $repository;
        $this->processor = $processor;
    }

    public function upload(UploadedFile $file): Media
    {
        try {
            DB::beginTransaction();

            $processedMedia = $this->processor->process($file);
            
            $media = $this->repository->create([
                'name' => $file->getClientOriginalName(),
                'file_name' => basename($processedMedia['original']),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $processedMedia['original'],
                'disk' => config('media.disk'),
                'processing_status' => 'completed',
                'metadata' => $processedMedia['metadata']
            ]);

            event(new MediaCreated($media));

            DB::commit();

            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Media upload failed', ['error' => $e->getMessage()]);
            throw new MediaException('Failed to upload media: ' . $e->getMessage());
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->repository->find($id);
            
            // Delete physical files
            Storage::disk($media->disk)->delete($media->path);
            foreach ($media->metadata['variants'] ?? [] as $variant) {
                Storage::disk($media->disk)->delete($variant);
            }

            $result = $this->repository->delete($id);

            event(new MediaDeleted($media));

            DB::commit();

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Media deletion failed', ['error' => $e->getMessage()]);
            throw new MediaException('Failed to delete media: ' . $e->getMessage());
        }
    }

    public function attachToContent(int $mediaId, int $contentId, string $type = 'default'): void
    {
        try {
            DB::beginTransaction();
            
            $this->repository->attachToContent($mediaId, $contentId, $type);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Media attachment failed', ['error' => $e->getMessage()]);
            throw new MediaException('Failed to attach media: ' . $e->getMessage());
        }
    }
}

namespace App\Core\Media\Http\Controllers;

use App\Core\Media\Services\MediaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    private MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|max:' . config('media.max_file_size')
            ]);

            $media = $this->mediaService->upload($request->file('file'));

            return response()->json($media, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->mediaService->delete($id);
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function attachToContent(Request $request, int $contentId): JsonResponse
    {
        try {
            $request->validate([
                'media_id' => 'required|integer',
                'type' => 'string|max:50'
            ]);

            $this->mediaService->attachToContent(
                $request->input('media_id'),
                $contentId,
                $request->input('type', 'default')
            );

            return response()->json(['message' => 'Media attached successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
