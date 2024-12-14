<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Content\ContentRepository;
use App\Core\Media\MediaManager;

class CMSCore implements CMSInterface
{
    protected SecurityManager $security;
    protected ContentRepository $content;
    protected MediaManager $media;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        ContentRepository $content,
        MediaManager $media,
        array $config
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
        $this->config = $config;
    }

    public function createContent(array $data): ContentEntity
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            return DB::transaction(function() use ($data) {
                $content = $this->content->create($data);
                
                if (!empty($data['media'])) {
                    $this->media->attachToContent($content->id, $data['media']);
                }

                Cache::tags(['content'])->flush();
                
                return $content;
            });
        });
    }

    public function updateContent(int $id, array $data): ContentEntity
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            $content = $this->content->findOrFail($id);
            
            return DB::transaction(function() use ($content, $data) {
                $updated = $this->content->update($content->id, $data);
                
                if (isset($data['media'])) {
                    $this->media->syncWithContent($content->id, $data['media']);
                }

                Cache::tags(['content', "content.{$content->id}"])->flush();
                
                return $updated;
            });
        });
    }

    public function deleteContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->content->findOrFail($id);
                
                $this->media->detachFromContent($id);
                $result = $this->content->delete($id);
                
                Cache::tags(['content', "content.{$id}"])->flush();
                
                return $result;
            });
        });
    }

    public function getContent(int $id): ?ContentEntity
    {
        return Cache::tags(["content.{$id}"])->remember(
            "content.{$id}",
            $this->config['cache_ttl'],
            fn() => $this->content->find($id)
        );
    }

    public function listContent(array $criteria = []): Collection
    {
        $cacheKey = 'content.list.' . md5(serialize($criteria));
        
        return Cache::tags(['content'])->remember(
            $cacheKey,
            $this->config['cache_ttl'],
            fn() => $this->content->findBy($criteria)
        );
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->content->findOrFail($id);
                $result = $this->content->publish($id);
                
                Cache::tags(['content', "content.{$id}"])->flush();
                
                return $result;
            });
        });
    }

    public function unpublishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->content->findOrFail($id);
                $result = $this->content->unpublish($id);
                
                Cache::tags(['content', "content.{$id}"])->flush();
                
                return $result;
            });
        });
    }

    public function versionContent(int $id): ContentVersion
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->content->findOrFail($id);
                return $this->content->createVersion($id);
            });
        });
    }

    public function restoreVersion(int $id, int $versionId): ContentEntity
    {
        return $this->security->executeCriticalOperation(function() use ($id, $versionId) {
            return DB::transaction(function() use ($id, $versionId) {
                $content = $this->content->findOrFail($id);
                $restored = $this->content->restoreVersion($id, $versionId);
                
                Cache::tags(['content', "content.{$id}"])->flush();
                
                return $restored;
            });
        });
    }

    protected function validateContentType(string $type): void
    {
        if (!in_array($type, $this->config['allowed_types'])) {
            throw new InvalidContentTypeException("Content type '{$type}' not allowed");
        }
    }

    protected function validateContentData(array $data): void
    {
        $validator = validator($data, [
            'title' => 'required|string|max:255',
            'type' => 'required|string',
            'content' => 'required|string',
            'status' => 'string|in:draft,published',
            'media.*' => 'integer|exists:media,id'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }
}
