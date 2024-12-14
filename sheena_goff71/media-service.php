<?php

namespace App\Core\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB, Cache};
use Illuminate\Support\Collection;
use App\Core\Models\Media;
use App\Core\Repositories\MediaRepository;
use App\Core\Services\{SecurityService, ValidationService, ImageService};
use App\Core\Events\{MediaUploaded, MediaDeleted};
use App\Core\Exceptions\{MediaException, ValidationException, SecurityException};

class MediaManager
{
    protected MediaRepository $repository;
    protected SecurityService $security;
    protected ValidationService $validator;
    protected ImageService $image;
    protected array $allowedMimes = ['jpeg', 'jpg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    protected int $maxFileSize = 10485760; // 10MB

    public function __construct(
        MediaRepository $repository,
        SecurityService $security,
        ValidationService $validator,
        ImageService $image
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->image = $image;
    }

    public function upload(UploadedFile $file, array $data = []): Media
    {
        return $this->security->executeSecure(function() use ($file, $data) {
            DB::beginTransaction();
            
            try {
                // Validate file
                $this->validateFile($file);

                // Generate safe filename
                $filename = $this->generateSecureFilename($file);
                
                // Process and store file
                $path = $this->processAndStore($file, $filename);
                
                // Create thumbnails for images
                $thumbnails = [];
                if ($this->isImage($file)) {
                    $thumbnails = $this->generateThumbnails($path);
                }

                // Create media record
                $mediaData = array_merge($data, [
                    'filename' => $filename,
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'thumbnails' => $thumbnails
                ]);

                $media = $this->repository->create($mediaData);

                DB::commit();
                
                // Clear media caches
                $this->clearMediaCaches();
                
                // Dispatch event
                event(new MediaUploaded($media));
                
                return $media;

            } catch (\Exception $e) {
                DB::rollBack();
                
                // Cleanup any stored files
                $this->cleanup($path ?? null, $thumbnails ?? []);
                
                throw new MediaException('Media upload failed: ' . $e->getMessage());
            }
        }, 'media.upload');
    }

    public function bulkUpload(array $files, array $data = []): Collection
    {
        return $this->security->executeSecure(function() use ($files, $data) {
            $uploaded = collect();
            
            DB::beginTransaction();
            
            try {
                foreach ($files as $file) {
                    $uploaded->push($this->upload($file, $data));
                }
                
                DB::commit();
                return $uploaded;

            } catch (\Exception $e) {
                DB::rollBack();
                
                // Cleanup uploaded files
                foreach ($uploaded as $media) {
                    $this->delete($media->id);
                }
                
                throw new MediaException('Bulk upload failed: ' . $e->getMessage());
            }
        }, 'media.bulk_upload');
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecure(function() use ($id) {
            $media = $this->repository->find($id);
            if (!$media) {
                throw new MediaException('Media not found');
            }

            DB::beginTransaction();
            
            try {
                // Delete physical files
                Storage::delete($media->path);
                
                foreach ($media->thumbnails as $thumbnail) {
                    Storage::delete($thumbnail);
                }

                // Delete database record
                $result = $this->repository->delete($id);

                DB::commit();
                
                // Clear caches
                $this->clearMediaCaches($id);
                
                // Dispatch event
                event(new MediaDeleted($media));
                
                return $result;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new MediaException('Failed to delete media: ' . $e->getMessage());
            }
        }, 'media.delete');
    }

    public function find(int $id): ?Media
    {
        return Cache::remember("media.{$id}", 3600, function() use ($id) {
            return $this->repository->find($id);
        });
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if ($file->getSize() > $this->maxFileSize) {
            throw new ValidationException('File size exceeds maximum allowed');
        }

        if (!in_array($file->getClientOriginalExtension(), $this->allowedMimes)) {
            throw new ValidationException('File type not allowed');
        }

        // Scan file for malware
        $this->security->scanFile($file);
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            time(),
            str_random(10),
            $file->getClientOriginalExtension()
        );
    }

    protected function processAndStore(UploadedFile $file, string $filename): string
    {
        $path = Storage::putFileAs(
            'media/' . date('Y/m'),
            $file,
            $filename,
            'public'
        );

        if (!$path) {
            throw new MediaException('Failed to store file');
        }

        return $path;
    }

    protected function generateThumbnails(string $path): array
    {
        $thumbnails = [];
        $sizes = [
            'small' => [150, 150],
            'medium' => [300, 300],
            'large' => [600, 600]
        ];

        foreach ($sizes as $size => [$width, $height]) {
            $thumbPath = "thumbnails/{$size}/" . basename($path);
            
            $this->image->resize(
                Storage::path($path),
                Storage::path($thumbPath),
                $width,
                $height
            );
            
            $thumbnails[$size] = $thumbPath;
        }

        return $thumbnails;
    }

    protected function cleanup(?string $path, array $thumbnails = []): void
    {
        if ($path) {
            Storage::delete($path);
        }

        foreach ($thumbnails as $thumbnail) {
            Storage::delete($thumbnail);
        }
    }

    protected function clearMediaCaches(int $id = null): void
    {
        if ($id) {
            Cache::forget("media.{$id}");
        }
        Cache::tags(['media'])->flush();
    }

    protected function isImage(UploadedFile $file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }
}
