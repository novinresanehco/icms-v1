<?php
namespace App\Core\CMS;

use App\Core\Security\{SecurityManager, ValidationService, AuditLogger};
use App\Core\Exceptions\{ContentException, SecurityException};
use Illuminate\Support\Facades\{DB, Cache};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $audit;
    private ContentRepository $repository;
    private MediaManager $media;

    public function createContent(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(function() use ($data, $context) {
            $validated = $this->validator->validateInput($data, $context);
            
            $content = DB::transaction(function() use ($validated, $context) {
                $content = $this->repository->create($validated);
                
                if (isset($validated['media'])) {
                    $this->media->attachToContent($content, $validated['media']);
                }
                
                $this->audit->logContentCreation($content, $context);
                Cache::tags('content')->flush();
                
                return $content;
            });

            return $this->repository->loadWithRelations($content->id);
        }, $context);
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $context) {
            $content = $this->repository->findOrFail($id);
            $this->validateContentAccess($content, $context);
            
            $validated = $this->validator->validateInput($data, $context);
            
            DB::transaction(function() use ($content, $validated, $context) {
                $this->repository->update($content, $validated);
                
                if (isset($validated['media'])) {
                    $this->media->syncWithContent($content, $validated['media']);
                }
                
                $this->audit->logContentUpdate($content, $context);
                $this->invalidateContentCache($content);
            });

            return $this->repository->loadWithRelations($content->id);
        }, $context);
    }

    public function deleteContent(int $id, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(function() use ($id, $context) {
            $content = $this->repository->findOrFail($id);
            $this->validateContentAccess($content, $context);
            
            DB::transaction(function() use ($content, $context) {
                $this->media->detachFromContent($content);
                $this->repository->delete($content);
                $this->audit->logContentDeletion($content, $context);
                $this->invalidateContentCache($content);
            });
        }, $context);
    }

    public function publishContent(int $id, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $context) {
            $content = $this->repository->findOrFail($id);
            $this->validatePublishAccess($content, $context);
            
            DB::transaction(function() use ($content, $context) {
                $this->repository->publish($content);
                $this->audit->logContentPublication($content, $context);
                $this->invalidateContentCache($content);
            });

            return $this->repository->loadWithRelations($content->id);
        }, $context);
    }

    private function validateContentAccess(Content $content, SecurityContext $context): void
    {
        if (!$this->security->checkAccess($content, $context)) {
            throw new SecurityException('Content access denied');
        }
    }

    private function validatePublishAccess(Content $content, SecurityContext $context): void
    {
        if (!$this->security->checkPublishAccess($content, $context)) {
            throw new SecurityException('Content publication access denied');
        }
    }

    private function invalidateContentCache(Content $content): void
    {
        Cache::tags(['content', "content_{$content->id}"])->flush();
    }
}
