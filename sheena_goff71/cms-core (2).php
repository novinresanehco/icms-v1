<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Services\ValidationService;
use Illuminate\Support\Facades\DB;

class ContentManager
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function store(array $data, array $media = []): Content
    {
        return DB::transaction(function() use ($data, $media) {
            $this->validator->validate($data);
            $this->security->validateAccess('content.create');
            
            $content = new Content($data);
            $content->save();
            
            if (!empty($media)) {
                $this->processMedia($content, $media);
            }
            
            $this->cache->invalidate(['content', 'content.list']);
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->findOrFail($id);
            $this->security->validateAccess('content.update', $content);
            
            $content->update($data);
            $this->cache->invalidate(['content.'.$id, 'content.list']);
            
            return $content;
        });
    }

    public function publish(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $content = $this->findOrFail($id);
            $this->security->validateAccess('content.publish', $content);
            
            if (!$this->validator->validateForPublish($content)) {
                throw new ValidationException('Content not ready for publishing');
            }
            
            $content->publish();
            $this->cache->invalidate(['content.'.$id, 'content.list']);
            
            return true;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $content = $this->findOrFail($id);
            $this->security->validateAccess('content.delete', $content);
            
            $content->delete();
            $this->cache->invalidate(['content.'.$id, 'content.list']);
            
            return true;
        });
    }

    public function get(int $id): Content
    {
        return $this->cache->remember('content.'.$id, function() use ($id) {
            $content = $this->findOrFail($id);
            $this->security->validateAccess('content.view', $content);
            return $content;
        });
    }

    public function list(array $filters = []): Collection
    {
        return $this->cache->remember('content.list', function() use ($filters) {
            $this->security->validateAccess('content.list');
            return Content::filter($filters)->get();
        });
    }

    protected function findOrFail(int $id): Content
    {
        $content = Content::find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }
        return $content;
    }

    protected function processMedia(Content $content, array $media): void
    {
        foreach ($media as $file) {
            $path = $file->store('content');
            $content->attachMedia($path);
        }
    }
}
