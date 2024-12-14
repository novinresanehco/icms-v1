<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityContext;
use App\Core\Content\ContentValidator;
use App\Core\Exceptions\ContentException;

class ContentManager 
{
    private SecurityManager $security;
    private ContentValidator $validator;
    private ContentRepository $repository;
    private CacheManager $cache;
    private int $cacheTime = 3600;

    public function __construct(
        SecurityManager $security,
        ContentValidator $validator,
        ContentRepository $repository,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->cache = $cache;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($data) {
                $validated = $this->validator->validateCreate($data);
                $content = $this->repository->create($validated);
                $this->cache->tags(['content'])->put(
                    $this->getCacheKey($content->id),
                    $content,
                    $this->cacheTime
                );
                return $content;
            },
            $context
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $data) {
                $validated = $this->validator->validateUpdate($data);
                $content = $this->repository->update($id, $validated);
                $this->cache->tags(['content'])->put(
                    $this->getCacheKey($id),
                    $content,
                    $this->cacheTime
                );
                return $content;
            },
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                $result = $this->repository->delete($id);
                $this->cache->tags(['content'])->forget(
                    $this->getCacheKey($id)
                );
                return $result;
            },
            $context
        );
    }

    public function find(int $id, SecurityContext $context): ?Content
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                return $this->cache->tags(['content'])->remember(
                    $this->getCacheKey($id),
                    $this->cacheTime,
                    fn() => $this->repository->find($id)
                );
            },
            $context
        );
    }

    public function publish(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id) {
                $content = $this->repository->find($id);
                if (!$content) {
                    throw new ContentException("Content not found: {$id}");
                }
                
                $published = $this->repository->publish($id);
                if ($published) {
                    $this->cache->tags(['content'])->forget(
                        $this->getCacheKey($id)
                    );
                }
                return $published;
            },
            $context
        );
    }

    private function getCacheKey(int $id): string
    {
        return "content.{$id}";
    }
}

class ContentRepository
{
    private DB $db;

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = DB::table('contents')->insertGetId($data);
            return $this->find($content);
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $updated = DB::table('contents')
                ->where('id', $id)
                ->update($data);
                
            if (!$updated) {
                throw new ContentException("Content update failed: {$id}");
            }
            
            return $this->find($id);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            return DB::table('contents')
                ->where('id', $id)
                ->delete() > 0;
        });
    }

    public function find(int $id): ?Content
    {
        $data = DB::table('contents')
            ->where('id', $id)
            ->first();
            
        return $data ? new Content($data) : null;
    }

    public function publish(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            return DB::table('contents')
                ->where('id', $id)
                ->update(['published' => true]) > 0;
        });
    }
}

class ContentValidator
{
    private array $createRules = [
        'title' => 'required|string|max:255',
        'content' => 'required|string',
        'type' => 'required|string',
        'status' => 'required|in:draft,published'
    ];

    private array $updateRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'type' => 'string',
        'status' => 'in:draft,published'
    ];

    public function validateCreate(array $data): array
    {
        return $this->validate($data, $this->createRules);
    }

    public function validateUpdate(array $data): array
    {
        return $this->validate($data, $this->updateRules);
    }

    private function validate(array $data, array $rules): array
    {
        $validator = validator($data, $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return $validator->validated();
    }
}

class CacheManager
{
    private Cache $cache;
    
    public function tags(array $tags)
    {
        return $this->cache->tags($tags);
    }
    
    public function remember(string $key, int $ttl, callable $callback)
    {
        return $this->cache->remember($key, $ttl, $callback);
    }
    
    public function forget(string $key): bool
    {
        return $this->cache->forget($key);
    }
    
    public function put(string $key, $value, $ttl): bool
    {
        return $this->cache->put($key, $value, $ttl);
    }
}

class Content
{
    public int $id;
    public string $title;
    public string $content;
    public string $type;
    public string $status;
    public ?string $published_at;
    public ?string $created_at;
    public ?string $updated_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
