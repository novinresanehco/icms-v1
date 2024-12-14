<?php

namespace App\Services;

use App\Repositories\Contracts\FileRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FileService
{
    protected FileRepositoryInterface $fileRepository;
    protected array $allowedMimeTypes;
    protected int $maxFileSize;

    public function __construct(FileRepositoryInterface $fileRepository)
    {
        $this->fileRepository = $fileRepository;
        $this->allowedMimeTypes = config('files.allowed_mime_types', []);
        $this->maxFileSize = config('files.max_size', 5242880); // 5MB default
    }

    public function uploadFile(UploadedFile $file, string $path = ''): ?string
    {
        $this->validateFile($file);
        return $this->fileRepository->store($file, $path);
    }

    public function uploadFileAs(UploadedFile $file, string $name, string $path = ''): ?string
    {
        $this->validateFile($file);
        return $this->fileRepository->storeAs($file, $name, $path);
    }

    public function deleteFile(string $path): bool
    {
        return $this->fileRepository->delete($path);
    }

    public function getFile(string $path): ?array
    {
        return $this->fileRepository->get($path);
    }

    public function getDirectoryFiles(string $directory): Collection
    {
        return $this->fileRepository->getAllInDirectory($directory);
    }

    public function moveFile(string $from, string $to): bool
    {
        return $this->fileRepository->move($from, $to);
    }

    public function copyFile(string $from, string $to): bool
    {
        return $this->fileRepository->copy($from, $to);
    }

    public function fileExists(string $path): bool
    {
        return $this->fileRepository->exists($path);
    }

    public function getFileSize(string $path): int
    {
        return $this->fileRepository->size($path);
    }

    public function getFileUrl(string $path): string
    {
        return $this->fileRepository->getUrl($path);
    }

    protected function validateFile(UploadedFile $file): void
    {
        $validator = Validator::make(
            ['file' => $file],
            [
                'file' => [
                    'required',
                    'file',
                    'max:' . ($this->maxFileSize / 1024),
                    function ($attribute, $value, $fail) use ($file) {
                        if (!empty($this->allowedMimeTypes) && !in_array($file->getMimeType(), $this->allowedMimeTypes)) {
                            $fail('The file type is not allowed.');
                        }
                    },
                ]
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
