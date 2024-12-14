<?php
namespace App\Core\CMS;

use Illuminate\Support\Facades\{Storage, DB};
use App\Core\Security\{SecurityManager, EncryptionService};
use App\Core\Exceptions\{MediaException, SecurityException};

class MediaManager implements MediaManagerInterface 
{
    private SecurityManager $security;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private MediaRepository $repository;
    private AuditLogger $audit;

    public function storeMedia(UploadedFile $file, SecurityContext $context): Media 
    {
        return $this->security->executeCriticalOperation(function() use ($file, $context) {
            $this->validateMediaFile($file);
            
            return DB::transaction(function() use ($file, $context) {
                $path = $this->processAndStore($file);
                $media = $this->repository->create([
                    'path' => $path,
                    'type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'hash' => $this->calculateFileHash($file),
                    'metadata' => $this->extractMetadata($file)
                ]);
                
                $this->audit->logMediaUpload($media, $context);
                return $media;
            });
        }, $context);
    }

    public function attachToContent(Content $content, array $mediaIds, SecurityContext $context): void 
    {
        $this->security->executeCriticalOperation(function() use ($content, $mediaIds, $context) {
            $media = $this->repository->findMany($mediaIds);
            
            DB::transaction(function() use ($content, $media, $context) {
                $this->validateMediaAttachment($content, $media);
                $content->media()->syncWithoutDetaching($media);
                $this->audit->logMediaAttachment($content, $media, $context);
            });
        }, $context);
    }

    public function retrieveMedia(int $id, SecurityContext $context): MediaResponse 
    {
        return $this->security->executeCriticalOperation(function() use ($id, $context) {
            $media = $this->repository->findOrFail($id);
            $this->validateMediaAccess($media, $context);
            
            $file = Storage::get($media->path);
            $decrypted = $this->encryption->decrypt($file);
            
            $this->audit->logMediaAccess($media, $context);
            
            return new MediaResponse($decrypted, $media->type);
        }, $context);
    }

    public function deleteMedia(int $id, SecurityContext $context): void 
    {
        $this->security->executeCriticalOperation(function() use ($id, $context) {
            $media = $this->repository->findOrFail($id);
            $this->validateMediaDeletion($media, $context);
            
            DB::transaction(function() use ($media, $context) {
                Storage::delete($media->path);
                $this->repository->delete($media);
                $this->audit->logMediaDeletion($media, $context);
            });
        }, $context);
    }

    private function processAndStore(UploadedFile $file): string 
    {
        $processed = $this->processMediaFile($file);
        $encrypted = $this->encryption->encrypt($processed);
        
        $path = Storage::putFile('media', $encrypted);
        if (!$path) {
            throw new MediaException('Failed to store media file');
        }
        
        return $path;
    }

    private function validateMediaFile(UploadedFile $file): void 
    {
        if (!$this->validator->validateMediaFile($file)) {
            throw new MediaException('Invalid media file');
        }

        if ($this->containsMalware($file)) {
            $this->audit->logMalwareDetection($file);
            throw new SecurityException('Malware detected in media file');
        }
    }

    private function validateMediaAccess(Media $media, SecurityContext $context): void 
    {
        if (!$this->security->checkAccess($media, $context)) {
            throw new SecurityException('Media access denied');
        }
    }

    private function validateMediaDeletion(Media $media, SecurityContext $context): void 
    {
        if (!$this->security->checkDeleteAccess($media, $context)) {
            throw new SecurityException('Media deletion access denied');
        }
    }

    private function validateMediaAttachment(Content $content, Collection $media): void 
    {
        foreach ($media as $item) {
            if (!$this->isValidForContent($item, $content)) {
                throw new MediaException("Invalid media attachment: {$item->id}");
            }
        }
    }

    private function processMediaFile(UploadedFile $file): string 
    {
        $processor = $this->getMediaProcessor($file->getMimeType());
        return $processor->process($file);
    }

    private function calculateFileHash(UploadedFile $file): string 
    {
        return hash_file('sha256', $file->getPathname());
    }

    private function containsMalware(UploadedFile $file): bool 
    {
        return $this->security->scanForMalware($file);
    }
}
