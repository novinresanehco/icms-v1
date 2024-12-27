<?php

namespace App\Core\Media;

use App\Core\Validation\BaseValidationService;
use Illuminate\Http\UploadedFile;

class MediaValidationService extends BaseValidationService
{
    private array $rules = [
        'filename' => 'required|string|max:255',
        'mime_type' => 'required|string|max:100',
        'size' => 'required|integer|min:1',
        'metadata' => 'array',
        'user_id' => 'required|integer|exists:users,id'
    ];

    public function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, config('media.allowed_types'))) {
            throw new ValidationException('Invalid file type');
        }

        if ($file->getSize() > config('media.max_size')) {
            throw new ValidationException('File size exceeds limit');
        }
    }

    public function validateMetadata(array $metadata): void
    {
        $rules = [
            'title' => 'string|max:255',
            'description' => 'string|max:1000',
            'tags' => 'array',
            'tags.*' => 'string|max:50'
        ];

        $this->validate($metadata, $rules);
    }
}
