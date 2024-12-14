namespace App\Core\Media;

use App\Core\Service\BaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, File};
use App\Core\Exceptions\{MediaException, ValidationException};

class MediaManagementService extends BaseService
{
    protected array $allowedMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    protected array $imageTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp'
    ];

    public function uploadMedia(UploadedFile $file, array $metadata = []): array
    {
        return $this->executeOperation(function() use ($file, $metadata) {
            $this->validateFile($file);
            
            $hash = $this->generateFileHash($file);
            $path = $this->generateStoragePath($file);
            
            $fileData = [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $hash,
                'path' => $path,
                'metadata' => $this->prepareMetadata($metadata)
            ];

            if ($this->isImage($file)) {
                $fileData['dimensions'] = $this->getImageDimensions($file);
                $this->generateThumbnails($file, $path);
            }

            $file->storeAs(
                dirname($path),
                basename($path),
                ['disk' => 'secure']
            );

            return $this->repository->create($fileData);
        }, ['action' => 'upload_media']);
    }

    public function getMedia(int $id): array
    {
        return $this->getCached(
            "media:$id",
            fn() => $this->repository->find($id)
        );
    }

    public function deleteMedia(int $id): bool
    {
        return $this->executeOperation(function() use ($id) {
            $media = $this->repository->find($id);
            
            if (!$media) {
                throw new MediaException('Media not found');
            }

            Storage::disk('secure')->delete($media['path']);
            
            if (isset($media['thumbnails'])) {
                foreach ($media['thumbnails'] as $thumbnail) {
                    Storage::disk('secure')->delete($thumbnail);
                }
            }

            return $this->repository->delete($id);
        }, ['action' => 'delete_media', 'id' => $id]);
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            throw new ValidationException('Unsupported file type');
        }

        if ($file->getSize() > config('media.max_file_size')) {
            throw new ValidationException('File size exceeds limit');
        }
    }

    protected function generateFileHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    protected function generateStoragePath(UploadedFile $file): string
    {
        $hash = $this->generateFileHash($file);
        $ext = $file->getClientOriginalExtension();
        
        return sprintf(
            'media/%s/%s/%s.%s',
            date('Y/m'),
            substr($hash, 0, 2),
            $hash,
            $ext
        );
    }

    protected function prepareMetadata(array $metadata): array
    {
        return array_merge($metadata, [
            'uploaded_at' => now(),
            'uploaded_by' => auth()->id(),
            'ip_address' => request()->ip()
        ]);
    }

    protected function isImage(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), $this->imageTypes);
    }

    protected function getImageDimensions(UploadedFile $file): array
    {
        $image = imagecreatefromstring(
            file_get_contents($file->getRealPath())
        );
        
        return [
            'width' => imagesx($image),
            'height' => imagesy($image)
        ];
    }

    protected function generateThumbnails(UploadedFile $file, string $path): void
    {
        $thumbnailSizes = config('media.thumbnail_sizes', [
            'small' => [150, 150],
            'medium' => [300, 300],
            'large' => [600, 600]
        ]);

        $image = imagecreatefromstring(
            file_get_contents($file->getRealPath())
        );

        foreach ($thumbnailSizes as $size => [$width, $height]) {
            $thumbnail = imagescale($image, $width, $height);
            
            $thumbnailPath = sprintf(
                '%s/%s_%s',
                dirname($path),
                $size,
                basename($path)
            );

            Storage::disk('secure')->put(
                $thumbnailPath,
                imagejpeg($thumbnail)
            );

            imagedestroy($thumbnail);
        }

        imagedestroy($image);
    }

    protected function getValidationRules(): array
    {
        return [
            'original_name' => 'required|string|max:255',
            'mime_type' => 'required|string|in:' . implode(',', $this->allowedMimeTypes),
            'size' => 'required|integer|max:' . config('media.max_file_size'),
            'hash' => 'required|string|size:64',
            'path' => 'required|string',
            'metadata' => 'array'
        ];
    }
}
