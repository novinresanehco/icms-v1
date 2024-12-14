<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\MediaException;
use Psr\Log\LoggerInterface;

class MediaManager implements MediaManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function processMedia(UploadedFile $file, array $options = []): MediaFile
    {
        $mediaId = $this->generateMediaId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('media:process', [
                'mime_type' => $file->getMimeType()
            ]);

            $this->validateMediaFile($file);
            $this->validateMediaOptions($options);

            $processedFile = $this->processMediaFile($file, $options);
            $this->validateProcessedMedia($processedFile);

            $this->storeMediaFile($mediaId, $processedFile);
            $this->logMediaOperation($mediaId, 'process');

            DB::commit();
            return new MediaFile($mediaId, $processedFile);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMediaFailure($mediaId, 'process', $e);
            throw new MediaException('Media processing failed', 0, $e);
        }
    }

    public function retrieveMedia(string $mediaId): MediaFile
    {
        try {
            $this->security->validateSecureOperation('media:retrieve', [
                'media_id' => $mediaId
            ]);

            $this->validateMediaId($mediaId);
            
            $file = $this->loadMediaFile($mediaId);
            $this->validateMediaAccess($file);

            $this->logMediaOperation($mediaId, 'retrieve');
            
            return $file;

        } catch (\Exception $e) {
            $this->handleMediaFailure($mediaId, 'retrieve', $e);
            throw new MediaException('Media retrieval failed', 0, $e);
        }
    }

    public function deleteMedia(string $mediaId): void
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('media:delete', [
                'media_id' => $mediaId
            ]);

            $this->validateMediaId($mediaId);
            $this->validateMediaDeletion($mediaId);

            $this->processMediaDeletion($mediaId);
            $this->logMediaOperation($mediaId, 'delete');

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMediaFailure($mediaId, 'delete', $e);
            throw new MediaException('Media deletion failed', 0, $e);
        }
    }

    private function validateMediaFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid media file');
        }

        if (!in_array($file->getMimeType(), $this->config['allowed_mime_types'])) {
            throw new MediaException('Unsupported media type');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException('File size exceeds limit');
        }
    }

    private function processMediaFile(UploadedFile $file, array $options): ProcessedFile
    {
        $processor = $this->getMediaProcessor($file->getMimeType());
        return $processor->process($file, $options);
    }

    private function validateProcessedMedia(ProcessedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Processed media validation failed');
        }

        if (!$this->validateMediaSecurity($file)) {
            throw new MediaException('Media security validation failed');
        }
    }

    private function validateMediaDeletion(string $mediaId): void
    {
        if (!$this->mediaExists($mediaId)) {
            throw new MediaException('Media file not found');
        }

        if (!$this->canDeleteMedia($mediaId)) {
            throw new MediaException('Media deletion not allowed');
        }
    }

    private function handleMediaFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('Media operation failed', [
            'media_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->notifyMediaFailure($id, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'allowed_mime_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'application/pdf'
            ],
            'max_file_size' => 10485760,
            'storage_path' => storage_path('media'),
            'processing_timeout' => 300,
            'secure_storage' => true
        ];
    }
}
