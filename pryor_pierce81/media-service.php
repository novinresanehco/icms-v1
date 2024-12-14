<?php

namespace App\Services;

use App\Models\Media;
use App\Interfaces\SecurityServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB};
use Illuminate\Support\Str;

class MediaService 
{
    private SecurityServiceInterface $security;
    private ValidationService $validator;
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    public function __construct(
        SecurityServiceInterface $security,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function uploadMedia(UploadedFile $file): Media 
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeUpload($file),
            ['action' => 'media.upload', 'permission' => 'media.create']
        );
    }

    private function executeUpload(UploadedFile $file): Media 
    {
        $this->validateFile($file);

        return DB::transaction(function() use ($file) {
            $fileName = $this->generateSecureFileName($file);
            $filePath = $file->storeAs(
                'media/' . date('Y/m'),
                $fileName,
                'secure'
            );

            return Media::create([
                'file_name' => $fileName,
                'file_path' => $filePath,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'status' => 'active',
                'created_by' => auth()->id()
            ]);
        });
    }

    public function deleteMedia(int $id): bool 
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeDelete($id),
            ['action' => 'media.delete', 'permission' => 'media.delete']
        );
    }

    private function executeDelete(int $id): bool 
    {
        return DB::transaction(function() use ($id) {
            $media = Media::findOrFail($id);
            
            // Remove physical file
            Storage::disk('secure')->delete($media->file_path);
            
            // Soft delete record
            return $media->delete();
        });
    }

    public function getSecureUrl(Media $media): string 
    {
        return $this->security->validateSecureOperation(
            fn() => $this->generateSecureUrl($media),
            ['action' => 'media.access', 'permission' => 'media.read']
        );
    }

    private function generateSecureUrl(Media $media): string 
    {
        $token = encrypt([
            'media_id' => $media->id,
            'expires' => now()->addMinutes(30)->timestamp
        ]);

        return route('media.serve', ['token' => $token]);
    }

    public function validateMediaAccess(string $token): Media 
    {
        try {
            $data = decrypt($token);
            
            if ($data['expires'] < now()->timestamp) {
                throw new MediaException('Media access token expired');
            }

            $media = Media::findOrFail($data['media_id']);
            
            if ($media->status !== 'active') {
                throw new MediaException('Media not available');
            }

            return $media;

        } catch (\Exception $e) {
            throw new MediaException('Invalid media access token');
        }
    }

    private function validateFile(UploadedFile $file): void 
    {
        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            throw new MediaException('Invalid file type');
        }

        if ($file->getSize() > config('media.max_size', 10485760)) { // 10MB default
            throw new MediaException('File size exceeds limit');
        }

        // Scan file for malware if possible
        if ($this->hasMalwareScanningEnabled()) {
            $this->scanFile($file);
        }
    }

    private function generateSecureFileName(UploadedFile $file): string 
    {
        return Str::uuid() . '.' . $file->getClientOriginalExtension();
    }

    private function hasMalwareScanningEnabled(): bool 
    {
        return config('media.malware_scanning', false);
    }

    private function scanFile(UploadedFile $file): void 
    {
        // Integrate with malware scanning service
        // Throw SecurityException if threat detected
    }

    public function optimizeImage(Media $media): bool 
    {
        if (!Str::startsWith($media->mime_type, 'image/')) {
            return false;
        }

        // Optimize image
        $path = Storage::disk('secure')->path($media->file_path);
        
        try {
            $image = Image::make($path);
            
            // Maintain aspect ratio, max dimensions 2000x2000
            $image->resize(2000, 2000, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            // Optimize quality
            $image->save($path, 80);
            
            // Update file size
            $media->update([
                'file_size' => filesize($path)
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Image optimization failed', [
                'media_id' => $media->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
