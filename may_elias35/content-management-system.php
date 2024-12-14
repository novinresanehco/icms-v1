<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private MediaManager $media;
    private AuditLogger $audit;

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->executeSecureOperation(function() use ($data) {
            $validated = $this->validator->validate($data, $this->getCreateRules());
            
            DB::beginTransaction();
            try {
                $content = $this->repository->create($validated);
                
                if (isset($validated['media'])) {
                    $this->media->attachToContent($content, $validated['media']);
                }
                
                DB::commit();
                $this->cache->invalidateContentCache($content);
                
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, $context, 'content.create');
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->executeSecureOperation(function() use ($id, $data) {
            $validated = $this->validator->validate($data, $this->getUpdateRules());
            
            DB::beginTransaction();
            try {
                $content = $this->repository->findOrFail($id);
                $this->repository->update($content, $validated);
                
                if (isset($validated['media'])) {
                    $this->media->syncContentMedia($content, $validated['media']);
                }
                
                DB::commit();
                $this->cache->invalidateContentCache($content);
                
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, $context, 'content.update');
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $content = $this->repository->findOrFail($id);
                $this->media->detachFromContent($content);
                $this->repository->delete($content);
                
                DB::commit();
                $this->cache->invalidateContentCache($content);
                
                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, $context, 'content.delete');
    }

    public function publish(int $id, SecurityContext $context): Content
    {
        return $this->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $content = $this->repository->findOrFail($id);
                $content->publish();
                $this->repository->save($content);
                
                DB::commit();
                $this->cache->invalidateContentCache($content);
                
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, $context, 'content.publish');
    }

    public function version(int $id, SecurityContext $context): ContentVersion
    {
        return $this->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $content = $this->repository->findOrFail($id);
                $version = $content->createVersion();
                $this->repository->saveVersion($version);
                
                DB::commit();
                return $version;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, $context, 'content.version');
    }

    private function executeSecureOperation(callable $operation, SecurityContext $context, string $permission): mixed
    {
        return $this->security->executeSecureOperation(
            new ContentOperation($operation, $permission),
            $context
        );
    }

    private function getCreateRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'media' => 'array',
            'media.*' => 'integer|exists:media,id'
        ];
    }

    private function getUpdateRules(): array
    {
        return [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'media' => 'array',
            'media.*' => 'integer|exists:media,id'
        ];
    }
}

class ContentRepository
{
    private ContentModel $model;
    private ContentVersionModel $versionModel;

    public function findOrFail(int $id): Content
    {
        $model = $this->model->findOrFail($id);
        return $this->hydrate($model);
    }

    public function create(array $data): Content
    {
        $model = $this->model->create($data);
        return $this->hydrate($model);
    }

    public function update(Content $content, array $data): bool
    {
        return $content->getModel()->update($data);
    }

    public function delete(Content $content): bool
    {
        return $content->getModel()->delete();
    }

    public function saveVersion(ContentVersion $version): bool
    {
        return $this->versionModel->create($version->toArray());
    }

    private function hydrate(ContentModel $model): Content
    {
        return new Content($model);
    }
}

class Content
{
    private ContentModel $model;
    private Collection $versions;

    public function publish(): void
    {
        $this->model->status = 'published';
        $this->model->published_at = now();
    }

    public function createVersion(): ContentVersion
    {
        return new ContentVersion([
            'content_id' => $this->model->id,
            'title' => $this->model->title,
            'content' => $this->model->content,
            'version' => $this->getNextVersionNumber()
        ]);
    }

    private function getNextVersionNumber(): int
    {
        return $this->versions->max('version') + 1;
    }

    public function getModel(): ContentModel
    {
        return $this->model;
    }
}

interface ContentManagerInterface
{
    public function create(array $data, SecurityContext $context): Content;
    public function update(int $id, array $data, SecurityContext $context): Content;
    public function delete(int $id, SecurityContext $context): bool;
    public function publish(int $id, SecurityContext $context): Content;
    public function version(int $id, SecurityContext $context): ContentVersion;
}
