<?php

namespace App\Core\Files\Models;

class File extends Model
{
    protected $fillable = [
        'name',
        'path',
        'mime_type',
        'size',
        'disk',
        'checksum',
        'metadata',
        'user_id'
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}

class FileVersion extends Model
{
    protected $fillable = [
        'file_id',
        'version',
        'path',
        'size',
        'checksum',
        'metadata'
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array'
    ];
}

namespace App\Core\Files\Services;

class FileManager
{
    private FileStorage $storage;
    private FileValidator $validator;
    private FileRepository $repository;
    private VersionManager $versionManager;

    public function store(UploadedFile $file, array $options = []): File
    {
        $this->validator->validate($file);
        
        $path = $this->storage->store($file, $options['path'] ?? null);
        $checksum = $this->calculateChecksum($file);
        
        return $this->repository->create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => $options['disk'] ?? config('filesystems.default'),
            'checksum' => $checksum,
            'metadata' => $options['metadata'] ?? [],
            'user_id' => auth()->id()
        ]);
    }

    public function update(int $fileId, UploadedFile $newFile): File
    {
        $file = $this->repository->find($fileId);
        $this->versionManager->createVersion($file);
        
        $path = $this->storage->store($newFile, dirname($file->path));
        $checksum = $this->calculateChecksum($newFile);
        
        return $this->repository->update($fileId, [
            'path' => $path,
            'mime_type' => $newFile->getMimeType(),
            'size' => $newFile->getSize(),
            'checksum' => $checksum
        ]);
    }

    public function delete(int $fileId): void
    {
        $file = $this->repository->find($fileId);
        $this->storage->delete($file->path);
        $this->repository->delete($fileId);
    }

    private function calculateChecksum(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }
}

class FileStorage
{
    private Storage $storage;

    public function store(UploadedFile $file, ?string $path = null): string
    {
        return $file->store($path ?? 'files');
    }

    public function get(string $path): ?string
    {
        return $this->storage->get($path);
    }

    public function delete(string $path): void
    {
        $this->storage->delete($path);
    }

    public function url(string $path): string
    {
        return $this->storage->url($path);
    }
}

class FileValidator
{
    private array $allowedTypes = [];
    private int $maxSize;

    public function validate(UploadedFile $file): void
    {
        if (!empty($this->allowedTypes) && !in_array($file->getMimeType(), $this->allowedTypes)) {
            throw new FileValidationException('File type not allowed');
        }

        if ($file->getSize() > $this->maxSize) {
            throw new FileValidationException('File size exceeds limit');
        }
    }
}

class VersionManager
{
    private FileRepository $repository;

    public function createVersion(File $file): FileVersion
    {
        $version = $this->getNextVersion($file);
        
        return FileVersion::create([
            'file_id' => $file->id,
            'version' => $version,
            'path' => $file->path,
            'size' => $file->size,
            'checksum' => $file->checksum,
            'metadata' => $file->metadata
        ]);
    }

    public function getVersion(File $file, int $version): ?FileVersion
    {
        return FileVersion::where('file_id', $file->id)
            ->where('version', $version)
            ->first();
    }

    private function getNextVersion(File $file): int
    {
        return FileVersion::where('file_id', $file->id)
            ->max('version') + 1;
    }
}

namespace App\Core\Files\Http\Controllers;

class FileController extends Controller
{
    private FileManager $fileManager;

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file',
            'path' => 'nullable|string',
            'metadata' => 'nullable|array'
        ]);

        try {
            $file = $this->fileManager->store(
                $request->file('file'),
                $request->only(['path', 'metadata'])
            );
            return response()->json($file, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => 'required|file']);

        try {
            $file = $this->fileManager->update($id, $request->file('file'));
            return response()->json($file);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->fileManager->delete($id);
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Files\Jobs;

class ProcessFileJob implements ShouldQueue
{
    private $file;

    public function handle(): void
    {
        // Process file (e.g., generate thumbnails, extract metadata)
    }
}
