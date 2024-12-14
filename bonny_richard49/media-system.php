<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Auth\AuthorizationInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB, Event};
use App\Core\Exceptions\{MediaException, ValidationException};

class MediaManager implements MediaManagerInterface
{
    private SecurityManagerInterface $security;
    private AuthorizationInterface $auth;
    private MediaRepository $repository;
    private ValidationService $validator;
    private ImageProcessor $imageProcessor;
    private StorageManager $storage;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'video/mp4', 'video/webm'
    ];

    private const MAX_FILE_SIZE = 52428800; // 50MB

    public function __construct(
        SecurityManagerInterface $security,
        AuthorizationInterface $auth,
        MediaRepository $repository,
        ValidationService $validator,
        ImageProcessor $imageProcessor,
        StorageManager $storage
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->imageProcessor = $imageProcessor;
        $this->storage = $storage;
    }

    public function upload(UploadedFile $file, array $metadata, User $user): Media
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleUpload($file, $metadata, $user),
            new SecurityContext('media-upload', [
                'user' => $user->id,
                'file_type' => $file->getMimeType()
            ])
        );
    }

    private function handleUpload(UploadedFile $file, array $metadata, User $user): Media
    {
        // Verify upload permission
        if (!$this->auth->checkPermission($user, 'media.upload')) {
            throw new MediaException('Unauthorized media upload attempt');
        }

        // Validate file
        $this->validateFile($file);

        // Validate metadata
        $validatedMetadata = $this->validator->validate($metadata, $this->getMetadataRules());

        DB::beginTransaction();
        try {
            // Process and store file
            $fileData = $this->processAndStoreFile($file);

            // Create media record
            $media = $this->repository->create([
                'filename' => $fileData['filename'],
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $fileData['path'],
                'metadata' => $validatedMetadata,
                'uploaded_by' => $user->id,
                'file_hash' => $fileData['hash']
            ]);

            // Process image variants if applicable
            if ($this->isImage($file)) {
                $this->processImageVariants($media, $file);
            }

            DB::commit();

            // Dispatch upload event
            Event::dispatch(new MediaEvents\MediaUploaded($media, $user));

            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->cleanup($fileData['path'] ?? null);
            throw new MediaException('Media upload failed: ' . $e->getMessage());
        }
    }

    private function processAndStoreFile(UploadedFile $file): array
    {
        // Generate secure filename
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = $file->getClientOriginalExtension();
        $filename = $hash . '.' . $extension;

        // Determine storage path
        $path = $this->generateStoragePath($filename);

        // Store file securely
        $this->storage->store($file, $path, [
            'visibility' => 'private',
            'mime_type' => $file->getMimeType()
        ]);

        return [
            'filename' => $filename,
            'path' => $path,
            'hash' => $hash
        ];
    }

    private function processImageVariants(Media $media, UploadedFile $file): void
    {
        $variants = $this->imageProcessor->createVariants($file, [
            'thumbnail' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 800, 'height' => null],
            'large' => ['width' => 1600, 'height' => null]
        ]);

        foreach ($variants as $type => $variantData) {
            $this->repository->addVariant($media, $type, $variantData);
        }
    }

    public function delete(int $id, User $user): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->handleDelete($id, $user),
            new SecurityContext('media-delete', [
                'media_id' => $id,
                'user' => $user->id
            ])
        );
    }

    private function handleDelete(int $id, User $user): void
    {
        $media = $this->repository->findOrFail($id);

        // Verify delete permission
        if (!$this->auth->checkPermission($user, 'media.delete', ['media' => $media])) {
            throw new MediaException('Unauthorized media deletion attempt');
        }

        DB::beginTransaction();
        try {
            // Delete file and variants
            $this->storage->delete($media->path);
            
            if ($media->variants) {
                foreach ($media->variants as $variant) {
                    $this->storage->delete($variant->path);
                }
            }

            // Delete database record
            $this->repository->delete($media->id);

            DB::commit();

            // Dispatch deletion event
            Event::dispatch(new MediaEvents\MediaDeleted($media, $user));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaException('Media deletion failed: ' . $e->getMessage());
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new ValidationException('Unsupported file type');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new ValidationException('File size exceeds limit');
        }

        // Scan for malware if configured
        if (config('media.security.malware_scanning')) {
            $this->scanFile($file);
        }
    }

    private function scanFile(UploadedFile $file): void
    {
        $scanner = app(MalwareScanner::class);
        if (!$scanner->isClean($file)) {
            throw new SecurityException('File failed security scan');
        }
    }

    private function isImage(UploadedFile $file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }

    private function generateStoragePath(string $filename): string
    {
        $prefix = substr($filename, 0, 2);
        return "uploads/{$prefix}/{$filename}";
    }

    private function cleanup(?string $path): void
    {
        if ($path && $this->storage->exists($path)) {
            $this->storage->delete($path);
        }
    }

    private function getMetadataRules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'alt_text' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50'
        ];
    }
}
