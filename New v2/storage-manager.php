<?php

namespace App\Core\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StorageManager implements StorageManagerInterface
{
    private string $disk;
    private array $allowedMimes;
    private int $maxFileSize;

    public function __construct(string $disk, array $allowedMimes, int $maxFileSize)
    {
        $this->disk = $disk;
        $this->allowedMimes = $allowedMimes;
        $this->maxFileSize = $maxFileSize;
    }

    public function store(UploadedFile $file): string
    {
        // Security validation
        $this->validateFile($file);

        // Generate secure path
        $path = $this->generateSecurePath($file);
        
        // Store with sanitized filename
        Storage::disk($this->disk)->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        return $path;
    }

    public function delete(string $path): void
    {
        if (!Storage::disk($this->disk)->exists($path)) {
            throw new StorageException('File not found');
        }

        Storage::disk($this->disk)->delete($path);
    }

    public function cleanup(?string $path): void
    {
        if ($path && Storage::disk($this->disk)->exists($path)) {
            Storage::disk($this->disk)->delete($path);
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedMimes)) {
            throw new StorageException('Invalid file type');
        }

        if ($file->getSize() > $this->maxFileSize) {
            throw new StorageException('File size exceeds limit');
        }
    }

    private function generateSecurePath(UploadedFile $file): string
    {
        $hash = hash('sha256', $file->getClientOriginalName() . time());
        $ext = $file->getClientOriginalExtension();
        return date('Y/m/d') . "/{$hash}.{$ext}";
    }
}
