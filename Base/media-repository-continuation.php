protected function generateResponsiveImages(Media $media, UploadedFile $file): void
{
    try {
        $responsiveConfig = config('media.responsive_sizes', [
            'sm' => ['width' => 640],
            'md' => ['width' => 768],
            'lg' => ['width' => 1024],
            'xl' => ['width' => 1280],
            '2xl' => ['width' => 1536]
        ]);

        $responsiveImages = [];
        $image = Image::make($file->path());
        $originalWidth = $image->width();

        foreach ($responsiveConfig as $size => $dimensions) {
            if ($dimensions['width'] >= $originalWidth) {
                continue;
            }

            $responsivePath = $this->getStoragePath($media->collection) . '/responsive/' . 
                             pathinfo($media->file_name, PATHINFO_FILENAME) . 
                             "_{$size}." . 
                             pathinfo($media->file_name, PATHINFO_EXTENSION);

            $resizedImage = clone $image;
            $resizedImage->resize($dimensions['width'], null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            Storage::disk($this->disk)->put($responsivePath, $resizedImage->encode());

            $responsiveImages[$size] = [
                'path' => $responsivePath,
                'url' => Storage::disk($this->disk)->url($responsivePath),
                'width' => $dimensions['width'],
                'height' => $resizedImage->height(),
            ];
        }

        if (!empty($responsiveImages)) {
            $media->update(['responsive_images' => $responsiveImages]);
        }
    } catch (\Exception $e) {
        Log::error('Failed to generate responsive images: ' . $e->getMessage());
    }
}

public function regenerateResponsiveImages(int $mediaId): bool
{
    try {
        $media = $this->model->findOrFail($mediaId);
        
        if (!$this->isImage($media)) {
            return false;
        }

        // Clear existing responsive images
        if (!empty($media->responsive_images)) {
            foreach ($media->responsive_images as $image) {
                Storage::disk($this->disk)->delete($image['path']);
            }
        }

        $file = new UploadedFile(
            Storage::disk($this->disk)->path($media->path),
            $media->file_name,
            $media->mime_type,
            null,
            true
        );

        $this->generateResponsiveImages($media, $file);
        return true;
    } catch (\Exception $e) {
        Log::error('Failed to regenerate responsive images: ' . $e->getMessage());
        return false;
    }
}

public function createFromUrl(string $url, array $metadata = []): ?int
{
    try {
        $tempFile = tempnam(sys_get_temp_dir(), 'media_');
        $contents = file_get_contents($url);
        
        if ($contents === false) {
            throw new \Exception('Failed to download file from URL');
        }

        file_put_contents($tempFile, $contents);

        $fileName = basename(parse_url($url, PHP_URL_PATH));
        $mimeType = mime_content_type($tempFile);

        $uploadedFile = new UploadedFile(
            $tempFile,
            $fileName,
            $mimeType,
            null,
            true
        );

        $mediaId = $this->store($uploadedFile, $metadata);

        @unlink($tempFile);

        return $mediaId;
    } catch (\Exception $e) {
        Log::error('Failed to create media from URL: ' . $e->getMessage());
        @unlink($tempFile ?? null);
        return null;
    }
}

public function duplicate(int $mediaId, array $metadata = []): ?int
{
    try {
        DB::beginTransaction();

        $originalMedia = $this->model->findOrFail($mediaId);
        $originalPath = Storage::disk($this->disk)->path($originalMedia->path);

        if (!Storage::disk($this->disk)->exists($originalMedia->path)) {
            throw new \Exception('Original media file not found');
        }

        $uploadedFile = new UploadedFile(
            $originalPath,
            $originalMedia->file_name,
            $originalMedia->mime_type,
            null,
            true
        );

        $newMetadata = array_merge(
            $originalMedia->metadata ?? [],
            $metadata,
            ['duplicated_from' => $mediaId]
        );

        $newMediaId = $this->store($uploadedFile, $newMetadata);

        DB::commit();
        return $newMediaId;
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Failed to duplicate media: ' . $e->getMessage());
        return null;
    }
}

public function validateUpload(UploadedFile $file): bool
{
    $maxSize = config('media.max_size', 10240); // Default 10MB
    $allowedMimes = config('media.allowed_mimes', [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'video/mp4',
        'audio/mpeg',
    ]);

    if ($file->getSize() > ($maxSize * 1024)) {
        Log::warning('File size exceeds maximum allowed size');
        return false;
    }

    if (!in_array($file->getMimeType(), $allowedMimes)) {
        Log::warning('File type not allowed');
        return false;
    }

    return true;
}

protected function scanFile(UploadedFile $file): bool
{
    try {
        // Implement virus scanning logic here
        // This is a placeholder for demonstration
        $scanner = new AntivirusScanner();
        return $scanner->scan($file->path());
    } catch (\Exception $e) {
        Log::error('Failed to scan file: ' . $e->getMessage());
        return false;
    }
}
