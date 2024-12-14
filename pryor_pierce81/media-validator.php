<?php

namespace App\Core\Validation;

use App\Core\Exceptions\MediaValidationException;
use Illuminate\Http\UploadedFile;

class MediaValidator
{
    protected array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'video/mp4'
    ];

    protected int $maxFileSize = 10485760; // 10MB

    public function validateUpload(UploadedFile $file): void
    {
        $errors = [];

        if (!$file->isValid()) {
            $errors[] = 'Invalid upload file';
        }

        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            $errors[] = "Unsupported file type: {$file->getMimeType()}";
        }

        if ($file->getSize() > $this->maxFileSize) {
            $errors[] = "File size exceeds maximum allowed size of " . ($this->maxFileSize / 1048576) . "MB";
        }

        if (!empty($errors)) {
            throw new MediaValidationException(implode(', ', $errors));
        }
    }

    public function validateAttachment(int $mediaId, int $contentId, string $type): void
    {
        $errors = [];

        if ($mediaId <= 0) {
            $errors[] = 'Invalid media ID';
        }

        if ($contentId <= 0) {
            $errors[] = 'Invalid content ID';
        }

        if (!in_array($type, ['image', 'video', 'document', 'attachment'])) {
            $errors[] = 'Invalid attachment type';
        }

        if (!empty($errors)) {
            throw new MediaValidationException(implode(', ', $errors));
        }
    }

    public function validateMetadata(array $metadata): void
    {
        $errors = [];

        foreach ($metadata as $key => $value) {
            if (!is_string($key)) {
                $errors[] = 'Metadata keys must be strings';
                break;
            }

            if (!is_scalar($value) && !is_array($value)) {
                $errors[] = 'Metadata values must be scalar values or arrays';
                break;
            }
        }

        if (!empty($errors)) {
            throw new MediaValidationException(implode(', ', $errors));
        }
    }
}
