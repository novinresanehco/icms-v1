<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Events\Content\{ContentCreated, ContentUpdated, ContentDeleted};

class ContentManager implements ContentInterface
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected ContentRepository $repository;
    protected VersionManager $versions;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        ContentRepository $repository,
        VersionManager $versions,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->versions = $versions;
        $this->config = $config;
    }

    public function create(array $data): ContentEntity
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            return DB::transaction(function() use ($data) {
                $validatedData = $this->validator->validate($data, $this->getValidationRules());
                
                $content = $this->repository->create($validatedData);
                $this->versions->createInitialVersion($content);
                
                $this->cache->tags(['content'])->flush();
                Event::dispatch(new ContentCreated($content));
                
                return $content;
            });
        });
    }

    public function update(int $id, array $data): ContentEntity
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            return DB::transaction(function() use ($id, $data) {
                $content = $this->repository->findOrFail($id);
                $validatedData = $this->validator->validate($data, $this->getValidationRules($id));
                
                $this->versions->createVersion($content);
                $updated = $this->repository->update($id, $validatedData);
                
                $this->cache->tags(['content', "content.{$id}"])->flush();
                Event::dispatch(new ContentUpdated($updated));
                
                return $updated;
            });
        });
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                $this->versions->archiveVersions($content);
                $result = $this->repository->delete($id);
                
                $this->cache->tags(['content', "content.{$id}"])->flush();
                Event::dispatch(new ContentDeleted($content));
                
                return $result;
            });
        });
    }

    public function find(int $id): ?ContentEntity
    {
        return $this->cache->tags(["content.{$id}"])->remember(
            "content.{$id}",
            fn() => $this->repository->find($id)
        );
    }

    public function findBySlug(string $slug): ?ContentEntity
    {
        return $this->cache->tags(['content'])->remember(
            "content.slug.{$slug}",
            fn() => $this->repository->findBySlug($slug)
        );
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                if (!$this->validateForPublishing($content)) {
                    throw new ValidationException('Content failed publishing validation');
                }
                
                $result = $this->repository->update($id, ['status' => 'published']);
                $this->cache->tags(['content', "content.{$id}"])->flush();
                
                return $result;
            });
        });
    }

    public function unpublish(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                $result = $this->repository->update($id, ['status' => 'draft']);
                $this->cache->tags(['content', "content.{$id}"])->flush();
                
                return $result;
            });
        });
    }

    public function restoreVersion(int $id, int $versionId): ContentEntity
    {
        return $this->security->executeCriticalOperation(function() use ($id, $versionId) {
            return DB::transaction(function() use ($id, $versionId) {
                $content = $this->repository->findOrFail($id);
                $version = $this->versions->findVersion($versionId);
                
                $this->versions->createVersion($content);
                $restored = $this->repository->update($id, $version->getData());
                
                $this->cache->tags(['content', "content.{$id}"])->flush();
                
                return $restored;
            });
        });
    }

    protected function getValidationRules(?int $id = null): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:contents,slug' . ($id ? ",{$id}" : ''),
            'content' => 'required|string',
            'type' => 'required|string|in:' . implode(',', $this->config['allowed_types']),
            'status' => 'string|in:draft,published',
            'metadata' => 'array'
        ];
    }

    protected function validateForPublishing(ContentEntity $content): bool
    {
        return $content->type !== 'template' || 
               $this->validator->validateTemplate($content->content);
    }
}
