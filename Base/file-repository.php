<?php

namespace App\Repositories;

use App\Models\File;
use App\Repositories\Contracts\FileRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileRepository implements FileRepositoryInterface
{
    protected File $model;
    protected string $disk;

    public function __construct(File $model, string $disk = 'public')
    {
        $this->model = $model;
        $this->disk = $disk;
    }

    public function store(UploadedFile $file, string $path = ''): ?string
    {
        try {
            DB::beginTransaction();

            $fileName = Str::random(40) . '.' . $file->getClientOriginalExtension();
            $storedPath = $file->storeAs($path, $fileName, $this->disk);

            if (!$storedPath) {
                throw new \Exception('Failed to store file');
            }

            $fileModel = $this->model->create([
                'name' => $fileName,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'disk' => $this->disk,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => [
                    'extension' => $file->getClientOriginalExtension(),
                    'dimensions' => $this->getImageDimensions($file),
                ],
            ]);

            DB::commit();
            return $storedPath;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to store file: ' . $e->getMessage());
            return null;
        }
    }

    public function storeAs(UploadedFile $file, string $name, string $path = ''): ?string
    {
        try {
            DB::beginTransaction();

            $storedPath = $file->storeAs($path, $name, $this->disk);

            if (!$storedPath) {
                throw new \Exception('Failed to store file');
            }

            $fileModel = $this->model->create([
                'name' => $name,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'disk' => $this->disk,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => [
                    'extension' => $file->getClientOriginalExtension(),
                    'dimensions' => $this->getImageDimensions($file),
                ],
            ]);

            DB::commit();
            return $storedPath;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to store file: ' . $e->getMessage());
            return null;
        }
    }

    public function delete(string $path): bool
    {
        try {
            DB::beginTransaction();

            $file = $this->model->where('path', $path)->first();
            if ($file) {
                $file->delete();
            }

            $deleted = Storage::disk($this->disk)->delete($path);

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete file: ' . $e->getMessage());
            return false;
        }
    }

    public function get(string $path): ?array
    {
        try {
            $file = $this->model->where('path', $path)->first();
            return $file ? $file->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get file: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllInDirectory(string $directory): Collection
    {
        try {
            return $this->model
                ->where('path', 'like', $directory . '%')
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to get files from directory: ' . $e->getMessage());
            return collect();
        }
    }

    public function move(string $from, string $to): bool
    {
        try {
            DB::beginTransaction();

            $moved = Storage::disk($this->disk)->move($from, $to);

            if ($moved) {
                $file = $this->model->where('path', $from)->first();
                if ($file) {
                    $file->update(['path' => $to]);
                }
            }

            DB::commit();
            return $moved;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to move file: ' . $e->getMessage());
            return false;
        }
    }

    public function copy(string $from, string $to): bool
    {
        try {
            DB::beginTransaction();

            $copied = Storage::disk($this->disk)->copy($from, $to);

            if ($copied) {
                $originalFile = $this->model->where('path', $from)->first();
                if ($originalFile) {
                    $this->model->create([
                        'name' => basename($to),
                        'original_name' => $originalFile->original_name,
                        'path' => dirname($to),
                        'disk' => $this->disk,
                        'mime_type' => $originalFile->mime_type,
                        'size' => $originalFile->size,
                        'metadata' => $originalFile->metadata,
                    ]);
                }
            }

            DB::commit();
            return $copied;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to copy file: ' . $e->getMessage());
            return false;
        }
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    public function size(string $path): int
    {
        try {
            return Storage::disk($this->disk)->size($path);
        } catch (\Exception $e) {
            Log::error('Failed to get file size: ' . $e->getMessage());
            return 0;
        }
    }

    public function lastModified(string $path): int
    {
        try {
            return Storage::disk($this->disk)->lastModified($path);
        } catch (\Exception $e) {
            Log::error('Failed to get last modified time: ' . $e->getMessage());
            return 0;
        }
    }

    public function getUrl(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }

    public function getMimeType(string $path): ?string
    {
        try {
            return Storage::disk($this->disk)->mimeType($path);
        } catch (\Exception $e) {
            Log::error('Failed to get mime type: ' . $e->getMessage());
            return null;
        }
    }

    protected function getImageDimensions(UploadedFile $file): ?array
    {
        if (strpos($file->getMimeType(), 'image/') === 0) {
            try {
                $image = getimagesize($file->path());
                return [
                    'width' => $image[0],
                    'height' => $image[1],
                ];
            } catch (\Exception $e) {
                Log::warning('Failed to get image dimensions: ' . $e->getMessage());
            }
        }
        return null;
    }
}
