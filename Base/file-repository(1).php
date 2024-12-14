<?php

namespace App\Core\Repositories;

use App\Models\File;
use App\Core\Services\Cache\CacheService;
use App\Core\Services\Storage\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class FileRepository extends AdvancedRepository
{
    protected $model = File::class;
    protected $storage;
    protected $cache;

    public function __construct(StorageService $storage, CacheService $cache)
    {
        parent::__construct();
        $this->storage = $storage;
        $this->cache = $cache;
    }

    public function upload(UploadedFile $file, string $directory = 'uploads', array $metadata = []): File
    {
        return $this->executeTransaction(function() use ($file, $directory, $metadata) {
            $path = $this->storage->store($file, $directory);
            
            return $this->create([
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => $file->getClientOriginalExtension(),
                'metadata' => $metadata,
                'directory' => $directory,
                'user_id' => auth()->id()
            ]);
        });
    }

    public function getByDirectory(string $directory): Collection
    {
        return $this->executeQuery(function() use ($directory) {
            return $this->cache->remember("files.directory.{$directory}", function() use ($directory) {
                return $this->model
                    ->where('directory', $directory)
                    ->orderBy('created_at', 'desc')
                    ->get();
            });
        });
    }

    public function delete(File $file): bool
    {
        return $this->executeTransaction(function() use ($file) {
            $this->storage->delete($file->path);
            $this->cache->forget("files.directory.{$file->directory}");
            return parent::delete($file);
        });
    }

    public function getDuplicates(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->select('hash', \DB::raw('COUNT(*) as count'))
                ->whereNotNull('hash')
                ->groupBy('hash')
                ->having('count', '>', 1)
                ->get()
                ->map(function($result) {
                    return $this->model
                        ->where('hash', $result->hash)
                        ->get();
                });
        });
    }
}
