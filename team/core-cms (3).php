<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\CoreSecurityManager;
use App\Core\Interfaces\{ContentManagerInterface, ValidationInterface};

class ContentManager implements ContentManagerInterface
{
    private CoreSecurityManager $security;
    private ValidationInterface $validator;
    private string $cachePrefix = 'content:';
    private int $cacheTTL = 3600;

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('create', $data, function() use ($data) {
                $validated = $this->validator->validate($data);
                
                DB::beginTransaction();
                try {
                    $content = new Content($validated);
                    $content->save();
                    
                    $this->processMedia($content, $data['media'] ?? []);
                    $this->updateCache($content);
                    
                    DB::commit();
                    return $content;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('update', $data, function() use ($id, $data) {
                $content = Content::findOrFail($id);
                $validated = $this->validator->validate($data);
                
                DB::beginTransaction();
                try {
                    $content->update($validated);
                    $this->processMedia($content, $data['media'] ?? []);
                    $this->updateCache($content);
                    
                    DB::commit();
                    return $content;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('delete', ['id' => $id], function() use ($id) {
                DB::beginTransaction();
                try {
                    $content = Content::findOrFail($id);
                    $content->delete();
                    $this->clearCache($content);
                    
                    DB::commit();
                    return true;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function get(int $id): ?Content
    {
        return Cache::remember(
            $this->getCacheKey($id),
            $this->cacheTTL,
            fn() => Content::findOrFail($id)
        );
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('publish', ['id' => $id], function() use ($id) {
                DB::beginTransaction();
                try {
                    $content = Content::findOrFail($id);
                    $content->publish();
                    $this->updateCache($content);
                    
                    DB::commit();
                    return true;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    private function processMedia(Content $content, array $media): void
    {
        $content->media()->sync($media);
    }

    private function updateCache(Content $content): void
    {
        Cache::put(
            $this->getCacheKey($content->id),
            $content,
            $this->cacheTTL
        );
    }

    private function clearCache(Content $content): void
    {
        Cache::forget($this->getCacheKey($content->id));
    }

    private function getCacheKey(int $id): string
    {
        return $this->cachePrefix . $id;
    }
}

class ContentOperation implements CriticalOperation
{
    private string $type;
    private array $data;
    private \Closure $operation;

    public function __construct(string $type, array $data, \Closure $operation)
    {
        $this->type = $type;
        $this->data = $data;
        $this->operation = $operation;
    }

    public function execute(): mixed
    {
        return ($this->operation)();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
