<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\CMSManagerInterface;

class CoreCMSManager implements CMSManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MediaHandler $media;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        MediaHandler $media,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->media = $media;
        $this->audit = $audit;
    }

    public function createContent(array $data, SecurityContext $context): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->validator, $this->media),
            $context
        );
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult
    {
        $operation = new UpdateContentOperation(
            $id,
            $data,
            $this->validator,
            $this->media,
            $this->cache
        );

        return $this->security->executeCriticalOperation($operation, $context);
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->cache),
            $context
        );
    }

    public function getContent(int $id, SecurityContext $context): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey('content', $id),
            config('cms.cache.ttl'),
            function() use ($id, $context) {
                return $this->security->executeCriticalOperation(
                    new GetContentOperation($id),
                    $context
                );
            }
        );
    }

    public function publishContent(int $id, SecurityContext $context): bool
    {
        $operation = new PublishContentOperation(
            $id, 
            $this->validator,
            $this->cache
        );

        return $this->security->executeCriticalOperation($operation, $context);
    }

    public function getRevisions(int $id, SecurityContext $context): array
    {
        return $this->cache->remember(
            $this->getCacheKey('revisions', $id),
            config('cms.cache.ttl'),
            function() use ($id, $context) {
                return $this->security->executeCriticalOperation(
                    new GetRevisionsOperation($id),
                    $context
                );
            }
        );
    }

    public function restoreRevision(int $contentId, int $revisionId, SecurityContext $context): ContentResult
    {
        $operation = new RestoreRevisionOperation(
            $contentId,
            $revisionId,
            $this->validator,
            $this->cache
        );

        return $this->security->executeCriticalOperation($operation, $context);
    }

    private function getCacheKey(string $type, int $id): string
    {
        return sprintf('cms:%s:%d', $type, $id);
    }
}

class CreateContentOperation implements CriticalOperation
{
    private array $data;
    private ValidationService $validator;
    private MediaHandler $media;
    
    public function __construct(array $data, ValidationService $validator, MediaHandler $media)
    {
        $this->data = $data;
        $this->validator = $validator;
        $this->media = $media;
    }

    public function execute(): ContentResult
    {
        $validated = $this->validator->validate($this->data, [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'media.*' => 'file|max:10240|mimes:jpeg,png,pdf'
        ]);

        DB::beginTransaction();
        
        try {
            $content = Content::create($validated);
            
            if (!empty($validated['media'])) {
                $this->media->processAndAttach($content, $validated['media']);
            }
            
            DB::commit();
            return new ContentResult($content);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getRequiredPermissions(): array
    {
        return ['content:create'];
    }
}

class UpdateContentOperation implements CriticalOperation
{
    private int $id;
    private array $data;
    private ValidationService $validator;
    private MediaHandler $media;
    private CacheManager $cache;

    public function __construct(
        int $id, 
        array $data, 
        ValidationService $validator,
        MediaHandler $media,
        CacheManager $cache
    ) {
        $this->id = $id;
        $this->data = $data;
        $this->validator = $validator;
        $this->media = $media;
        $this->cache = $cache;
    }

    public function execute(): ContentResult
    {
        $content = Content::findOrFail($this->id);
        
        $validated = $this->validator->validate($this->data, [
            'title' => 'string|max:200',
            'content' => 'string',
            'status' => 'in:draft,published',
            'media.*' => 'file|max:10240|mimes:jpeg,png,pdf'
        ]);

        DB::beginTransaction();
        
        try {
            ContentRevision::create([
                'content_id' => $content->id,
                'data' => $content->toArray()
            ]);

            $content->update($validated);
            
            if (!empty($validated['media'])) {
                $this->media->processAndAttach($content, $validated['media']);
            }
            
            $this->cache->invalidate($this->getCacheKey('content', $content->id));
            $this->cache->invalidate($this->getCacheKey('revisions', $content->id));
            
            DB::commit();
            return new ContentResult($content);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getValidationRules(): array
    {
        return [
            'title' => 'string|max:200',
            'content' => 'string',
            'status' => 'in:draft,published'
        ];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getRequiredPermissions(): array
    {
        return ['content:update'];
    }

    private function getCacheKey(string $type, int $id): string
    {
        return sprintf('cms:%s:%d', $type, $id);
    }
}
