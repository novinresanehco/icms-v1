<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\FileRepositoryInterface;
use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FileRepository extends BaseRepository implements FileRepositoryInterface
{
    public function __construct(File $model)
    {
        parent::__construct($model);
    }

    public function store(UploadedFile $file, array $options = []): File
    {
        $path = $this->generatePath($file, $options);
        $disk = $options['disk'] ?? config('filesystems.default');
        
        // Store the file
        $storagePath = $file->storeAs(
            $path,
            $this->generateFilename($file),
            ['disk' => $disk]
        );

        // Create file record
        return $this->create([
            'name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $storagePath,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'folder_id' => $options['folder_id'] ?? null,
            'user_id' => auth()->id(),
            'visibility' => $options['visibility'] ?? 'private',
            'metadata' => $this->generateMetadata($file, $options)
        ]);
    }

    public function getByFolder(?int $folderId = null): Collection
    {
        return $this->model
            ->where('folder_id', $folderId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getByType(string $type): Collection
    {
        return $this->model
            ->where('mime_type', 'LIKE', $type . '%')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function duplicate(int $fileId, ?int $targetFolderId = null): ?File
    {
        $file = $this->find($fileId);
        if (!$file) {
            return null;
        }

        // Generate new path and copy file
        $newPath = $this->generateDuplicatePath($file);
        Storage::disk($file->disk)->copy($file->path, $newPath);

        // Create duplicate record
        return $this->create([
            'name' => $this->generateDuplicateName($file->name),
            'disk' => $file->disk,
            'path' => $newPath,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'folder_id' => $targetFolderId ?? $file->folder_id,
            'user_id' => auth()->id(),
            'visibility' => $file->visibility,
            'metadata' => $file->metadata
        ]);
    }

    public function move(int $fileId, int $targetFolderId): bool
    {
        return $this->update($fileId, [
            'folder_id' => $targetFolderId
        ]);
    }

    public function updateVisibility(int $fileId, string $visibility): bool
    {
        $file = $this->find($fileId);
        if (!$file) {
            return false;
        }

        Storage::disk($file->disk)->setVisibility($file->path, $visibility);

        return $this->update($fileId, [
            'visibility' => $visibility
        ]);
    }

    public function getFileUrl(int $fileId, int $expirationMinutes = 5): ?string
    {
        $file = $this->find($fileId);
        if (!$file) {
            return null;
        }

        return Storage::disk($file->disk)->temporaryUrl(
            $file->path,
            now()->addMinutes($expirationMinutes)
        );
    }

    public function getRecentFiles(int $limit = 10): Collection
    {
        return $this->model
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    protected function generatePath(UploadedFile $file, array $options): string
    {
        $basePath = $options['path'] ?? 'uploads';
        return trim($basePath, '/') . '/' . date('Y/m/d');
    }

    protected function generateFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            Str::random(32),
            time(),
            $file->getClientOriginalExtension()
        );
    }

    protected function generateMetadata(UploadedFile $file, array $options): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'upload_ip' => request()->ip(),
            'upload_agent' => request()->userAgent()
        ];

        if (isset($options['metadata'])) {
            $metadata = array_merge($metadata, $options['metadata']);
        }

        return $metadata;
    }

    protected function generateDuplicatePath(File $file): string
    {
        $pathInfo = pathinfo($file->path);
        return $pathInfo['dirname'] . '/' . Str::random(32) . '_' . time() . '.' . $pathInfo['extension'];
    }

    protected function generateDuplicateName(string $originalName): string
    {
        $pathInfo = pathinfo($originalName);
        return $pathInfo['filename'] . ' (copy).' . $pathInfo['extension'];
    }
}
