<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use App\Core\Interfaces\MediaManagerInterface;
use App\Core\Services\{SecurityManager, ValidationService, CacheManager};
use App\Core\Exceptions\MediaException;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private array $config;
    private array $processors = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = $config;
        $this->initializeProcessors();
    }

    public function upload(UploadedFile $file, array $options = []): array
    {
        try {
            $this->security->validateOperation('media.upload', ['file' => $file->getClientOriginalName()]);
            $this->validateFile($file);

            return DB::transaction(function() use ($file, $options) {
                $path = $this->storeFile($file);
                $mediaData = $this->createMediaRecord($file, $path, $options);
                
                $this->processFile($mediaData['id'], $path, $options);
                $this->invalidateCache($mediaData['id']);
                
                return $mediaData;
            });
        } catch (\Exception $e) {
            throw new MediaException('Media upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function get(int $id, array $options = []): array
    {
        try {
            $this->security->validateOperation('media.read', ['id' => $id]);
            
            return $this->cache->remember(
                $this->getCacheKey($id, $options),
                fn() => $this->fetchMedia($id, $options)
            );
        } catch (\Exception $e) {
            throw new MediaException('Media retrieval failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            $this->security->validateOperation('media.delete', ['id' => $id]);

            return DB::transaction(function() use ($id) {
                $media = $this->fetchMedia($id, ['include_variants' => true]);
                
                $this->deleteFiles($media);
                $this->deleteMediaRecord($id);
                $this->invalidateCache($id);
                
                return true;
            });
        } catch (\Exception $e) {
            throw new MediaException('Media deletion failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function process(int $id, array $options = []): array
    {
        try {
            $this->security->validateOperation('media.process', ['id' => $id]);

            return DB::transaction(function() use ($id, $options) {
                $media = $this->fetchMedia($id, []);
                $this->processFile($id, $media['path'], $options);
                $this->invalidateCache($id);
                
                return $this->fetchMedia($id, ['include_variants' => true]);
            });
        } catch (\Exception $e) {
            throw new MediaException('Media processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        $maxSize = $this->config['max_size'] ?? 10 * 1024 * 1024;
        $allowedTypes = $this->config['allowed_types'] ?? ['image/jpeg', 'image/png'];

        if ($file->getSize() > $maxSize) {
            throw new MediaException('File size exceeds limit');
        }

        if (!in_array($file->getMimeType(), $allowedTypes)) {
            throw new MediaException('File type not allowed');
        }

        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }
    }

    protected function storeFile(UploadedFile $file): string
    {
        $name = $this->generateFileName($file);
        $path = $file->storeAs(
            $this->config['storage_path'],
            $name,
            $this->config['storage_disk'] ?? 'public'
        );

        if ($path === false) {
            throw new MediaException('File storage failed');
        }

        return $path;
    }

    protected function createMediaRecord(UploadedFile $file, string $path, array $options): array
    {
        $mediaData = [
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => json_encode($options['metadata'] ?? []),
            'created_at' => time(),
            'updated_at' => time()
        ];

        $id = DB::table($this->config['tables']['media'])->insertGetId($mediaData);
        return array_merge($mediaData, ['id' => $id]);
    }

    protected function processFile(int $id, string $path, array $options): void
    {
        foreach ($this->processors as $processor) {
            if ($processor->canProcess($path)) {
                $variants = $processor->process($path, $options);
                $this->storeVariants($id, $variants);
            }
        }
    }

    protected function storeVariants(int $id, array $variants): void
    {
        foreach ($variants as $variant) {
            DB::table($this->config['tables']['variants'])->insert([
                'media_id' => $id,
                'type' => $variant['type'],
                'path' => $variant['path'],
                'metadata' => json_encode($variant['metadata'] ?? []),
                'created_at' => time()
            ]);
        }
    }

    protected function fetchMedia(int $id, array $options): array
    {
        $media = DB::table($this->config['tables']['media'])
            ->where('id', $id)
            ->first();

        if (!$media) {
            throw new MediaException('Media not found');
        }

        $result = (array)$media;

        if ($options['include_variants'] ?? false) {
            $result['variants'] = $this->fetchVariants($id);
        }

        return $result;
    }

    protected function fetchVariants(int $id): array
    {
        return DB::table($this->config['tables']['variants'])
            ->where('media_id', $id)
            ->get()
            ->map(fn($variant) => (array)$variant)
            ->all();
    }

    protected function deleteFiles(array $media): void
    {
        $storage = storage_disk($this->config['storage_disk'] ?? 'public');
        
        if ($storage->exists($media['path'])) {
            $storage->delete($media['path']);
        }

        foreach ($media['variants'] ?? [] as $variant) {
            if ($storage->exists($variant['path'])) {
                $storage->delete($variant['path']);
            }
        }
    }

    protected function deleteMediaRecord(int $id): void
    {
        DB::table($this->config['tables']['variants'])
            ->where('media_id', $id)
            ->delete();

        DB::table($this->config['tables']['media'])
            ->where('id', $id)
            ->delete();
    }

    protected function generateFileName(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            time(),
            uniqid(),
            $file->getClientOriginalExtension()
        );
    }

    protected function initializeProcessors(): void
    {
        foreach ($this->config['processors'] ?? [] as $processor) {
            $this->processors[] = new $processor($this->config);
        }
    }

    protected function getCacheKey(int $id, array $options): string
    {
        return "media:{$id}:" . md5(json_encode($options));
    }

    protected function invalidateCache(int $id): void
    {
        $this->cache->tags(['media', "media:{$id}"])->flush();
    }
}
