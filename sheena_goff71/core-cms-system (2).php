<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $auditor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $auditor,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->config = $config;
    }

    public function createContent(array $data, array $context): ContentResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentCreation($data),
            $this->buildOperationContext('create', $context)
        );
    }

    public function updateContent(int $id, array $data, array $context): ContentResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentUpdate($id, $data),
            $this->buildOperationContext('update', $context, $id)
        );
    }

    public function deleteContent(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentDeletion($id),
            $this->buildOperationContext('delete', $context, $id)
        );
    }

    public function publishContent(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentPublication($id),
            $this->buildOperationContext('publish', $context, $id)
        );
    }

    public function versionContent(int $id, array $context): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentVersioning($id),
            $this->buildOperationContext('version', $context, $id)
        );
    }

    protected function executeContentCreation(array $data): ContentResult
    {
        $validated = $this->validateContentData($data);
        
        DB::beginTransaction();
        try {
            $content = $this->insertContent($validated);
            $this->processContentMetadata($content->id, $validated);
            $this->processContentRelations($content->id, $validated);
            
            DB::commit();
            $this->invalidateContentCache();
            
            return new ContentResult($content, true);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentOperationException('Content creation failed', 0, $e);
        }
    }

    protected function executeContentUpdate(int $id, array $data): ContentResult
    {
        $validated = $this->validateContentData($data);
        $existing = $this->findContent($id);

        if (!$existing) {
            throw new ContentNotFoundException("Content {$id} not found");
        }

        DB::beginTransaction();
        try {
            $this->createContentVersion($existing);
            $content = $this->updateContentRecord($id, $validated);
            $this->updateContentMetadata($id, $validated);
            $this->updateContentRelations($id, $validated);
            
            DB::commit();
            $this->invalidateContentCache($id);
            
            return new ContentResult($content, true);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentOperationException('Content update failed', 0, $e);
        }
    }

    protected function executeContentDeletion(int $id): bool
    {
        $existing = $this->findContent($id);

        if (!$existing) {
            throw new ContentNotFoundException("Content {$id} not found");
        }

        DB::beginTransaction();
        try {
            $this->createContentVersion($existing);
            $this->deleteContentRelations($id);
            $this->deleteContentMetadata($id);
            $this->deleteContentRecord($id);
            
            DB::commit();
            $this->invalidateContentCache($id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentOperationException('Content deletion failed', 0, $e);
        }
    }

    protected function executeContentPublication(int $id): bool
    {
        $content = $this->findContent($id);

        if (!$content) {
            throw new ContentNotFoundException("Content {$id} not found");
        }

        DB::beginTransaction();
        try {
            $this->validatePublicationRules($content);
            $this->updatePublicationStatus($id, true);
            $this->processPublicationHooks($content);
            
            DB::commit();
            $this->invalidateContentCache($id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentOperationException('Content publication failed', 0, $e);
        }
    }

    protected function executeContentVersioning(int $id): ContentVersion
    {
        $content = $this->findContent($id);

        if (!$content) {
            throw new ContentNotFoundException("Content {$id} not found");
        }

        DB::beginTransaction();
        try {
            $version = $this->createContentVersion($content);
            
            DB::commit();
            return $version;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentOperationException('Content versioning failed', 0, $e);
        }
    }

    protected function buildOperationContext(string $operation, array $context, ?int $id = null): array
    {
        return array_merge($context, [
            'operation' => $operation,
            'content_id' => $id,
            'timestamp' => microtime(true),
            'system_id' => $this->config['system_id']
        ]);
    }

    protected function validateContentData(array $data): array
    {
        return $this->validator->validateContent($data, $this->config['validation_rules']);
    }

    protected function validatePublicationRules(Content $content): void
    {
        $rules = $this->config['publication_rules'];
        if (!$this->validator->validatePublicationRules($content, $rules)) {
            throw new ContentValidationException('Content failed publication validation');
        }
    }

    protected function invalidateContentCache(?int $id = null): void
    {
        if ($id) {
            Cache::forget("content:{$id}");
        }
        Cache::tags(['content'])->flush();
    }

    protected function findContent(int $id): ?Content
    {
        return Cache::remember(
            "content:{$id}",
            $this->config['cache_ttl'],
            fn() => Content::find($id)
        );
    }
}

class ContentOperationException extends \RuntimeException {}
class ContentNotFoundException extends \RuntimeException {}
class ContentValidationException extends \RuntimeException {}
