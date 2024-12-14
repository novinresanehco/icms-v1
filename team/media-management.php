<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Performance\PerformanceManager;
use App\Core\Media\Processors\{ImageProcessor, VideoProcessor};
use App\Core\Media\Events\MediaEvent;
use App\Core\Media\DTOs\{MediaData, ProcessingResult};
use App\Core\Exceptions\{MediaException, ValidationException};

class MediaManager implements MediaInterface
{
    private SecurityManager $security;
    private PerformanceManager $performance;
    private MediaRepository $repository;
    private ValidationService $validator;
    private ImageProcessor $imageProcessor;
    private VideoProcessor $videoProcessor;
    private AuditLogger $auditLogger;

    public function upload(UploadedFile $file, array $options = []): MediaData
    {
        return $this->security->executeCriticalOperation(
            new UploadOperation($file, $options),
            new SecurityContext(['type' => 'media_upload']),
            function() use ($file, $options) {
                try {
                    $validated = $this->validator->validateUpload($file);
                    
                    $hash = hash_file('sha256', $file->getPathname());
                    if ($existing = $this->findByHash($hash)) {
                        return new MediaData($existing);
                    }

                    $path = $this->storeSecurely($file);
                    $metadata = $this->extractMetadata($file);

                    $media = $this->repository->create([
                        'path' => $path,
                        'filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'hash' => $hash,
                        'metadata' => $metadata
                    ]);

                    $this->processAsync($media, $options);
                    $this->auditLogger->logMediaUpload($media);
                    
                    event(new MediaEvent(MediaEvent::UPLOADED, $media));
                    
                    return new MediaData($media);
                    
                } catch (\Exception $e) {
                    $this->auditLogger->logUploadFailure($file, $e);
                    throw new MediaException('Media upload failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    protected function storeSecurely(UploadedFile $file): string
    {
        $name = uniqid('media_') . '.' . $file->getClientOriginalExtension();
        $path = Storage::disk('secure')->putFileAs(
            date('Y/m/d'),
            $file,
            $name
        );

        if (!$path) {
            throw new MediaException('Failed to store file securely');
        }

        return $path;
    }

    protected function processAsync(Media $media, array $options): void
    {
        dispatch(new ProcessMediaJob($media, $options))
            ->onQueue('media-processing');
    }

    public function process(int $mediaId, array $options = []): ProcessingResult
    {
        return $this->security->executeCriticalOperation(
            new ProcessOperation($mediaId, $options),
            new SecurityContext(['type' => 'media_processing']),
            function() use ($mediaId, $options) {
                try {
                    $media = $this->repository->findOrFail($mediaId);
                    
                    return $this->performance->withCaching(
                        "media_processing:{$mediaId}",
                        fn() => $this->processMedia($media, $options),
                        ['media', "media:{$mediaId}"],
                        3600
                    );
                } catch (\Exception $e) {
                    $this->auditLogger->logProcessingFailure($mediaId, $e);
                    throw new MediaException('Media processing failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    protected function processMedia(Media $media, array $options): ProcessingResult
    {
        $processor = $this->getProcessor($media->mime_type);
        $result = $processor->process($media, $options);
        
        $this->repository->update($media->id, [
            'processed' => true,
            'variants' => $result->variants,
            'metadata' => array_merge(
                $media->metadata ?? [],
                $result->metadata ?? []
            )
        ]);

        $this->auditLogger->logMediaProcessed($media);
        event(new MediaEvent(MediaEvent::PROCESSED, $media));
        
        return $result;
    }

    protected function getProcessor(string $mimeType): MediaProcessorInterface
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => $this->imageProcessor,
            str_starts_with($mimeType, 'video/') => $this->videoProcessor,
            default => throw new MediaException('Unsupported media type')
        };
    }

    protected function findByHash(string $hash): ?Media
    {
        return $this->repository->findByHash($hash);
    }

    public function delete(int $mediaId): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteOperation($mediaId),
            new SecurityContext(['type' => 'media_deletion']),
            function() use ($mediaId) {
                try {
                    $media = $this->repository->findOrFail($mediaId);
                    
                    Storage::disk('secure')->delete($media->path);
                    foreach ($media->variants ?? [] as $variant) {
                        Storage::disk('secure')->delete($variant['path']);
                    }

                    $this->repository->delete($mediaId);
                    $this->auditLogger->logMediaDeletion($media);
                    
                    event(new MediaEvent(MediaEvent::DELETED, $media));
                    
                    return true;
                    
                } catch (\Exception $e) {
                    $this->auditLogger->logDeletionFailure($mediaId, $e);
                    throw new MediaException('Media deletion failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    protected function extractMetadata(UploadedFile $file): array
    {
        try {
            return match ($file->getMimeType()) {
                'image/jpeg', 'image/png' => $this->extractImageMetadata($file),
                'video/mp4' => $this->extractVideoMetadata($file),
                default => []
            };
        } catch (\Exception $e) {
            $this->auditLogger->logMetadataExtractionFailure($file, $e);
            return [];
        }
    }
}
