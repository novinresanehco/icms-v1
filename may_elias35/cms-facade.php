<?php

namespace App\Core\CMS;

class CMSFacade implements CMSInterface
{
    private ContentManager $content;
    private SecurityManager $security;
    private ValidationService $validator;
    private TransactionManager $transaction;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function __construct(
        ContentManager $content,
        SecurityManager $security,
        ValidationService $validator,
        TransactionManager $transaction,
        AuditLogger $logger,
        CacheManager $cache
    ) {
        $this->content = $content;
        $this->security = $security;
        $this->validator = $validator;
        $this->transaction = $transaction;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function createContent(array $data): ContentResult
    {
        return $this->transaction->execute(function() use ($data) {
            $this->validator->validateContentData($data);
            $this->security->enforceCreatePermissions();
            
            $sanitizedData = $this->security->sanitizeInput($data);
            $content = $this->content->create($sanitizedData);
            
            $this->cache->invalidateContentCache();
            $this->logger->logContentCreation($content->getId());
            
            return new ContentResult($content);
        });
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        return $this->transaction->execute(function() use ($id, $data) {
            $this->validator->validateContentData($data);
            $this->security->enforceUpdatePermissions($id);
            
            $sanitizedData = $this->security->sanitizeInput($data);
            $content = $this->content->update($id, $sanitizedData);
            
            $this->cache->invalidateContentCache($id);
            $this->logger->logContentUpdate($id);
            
            return new ContentResult($content);
        });
    }

    public function deleteContent(int $id): bool
    {
        return $this->transaction->execute(function() use ($id) {
            $this->security->enforceDeletePermissions($id);
            
            $deleted = $this->content->delete($id);
            $this->cache->invalidateContentCache($id);
            $this->logger->logContentDeletion($id);
            
            return $deleted;
        });
    }

    public function getContent(int $id): ?ContentResult
    {
        return $this->cache->remember("content:{$id}", 3600, function() use ($id) {
            $this->security->enforceViewPermissions($id);
            
            $content = $this->content->find($id);
            if ($content) {
                $this->logger->logContentAccess($id);
            }
            
            return $content ? new ContentResult($content) : null;
        });
    }

    public function searchContent(array $criteria): Collection
    {
        $this->validator->validateSearchCriteria($criteria);
        $this->security->enforceSearchPermissions();
        
        $sanitizedCriteria = $this->security->sanitizeInput($criteria);
        $cacheKey = "content:search:" . md5(serialize($sanitizedCriteria));
        
        return $this->cache->remember($cacheKey, 3600, function() use ($sanitizedCriteria) {
            $results = $this->content->search($sanitizedCriteria);
            $this->logger->logContentSearch($sanitizedCriteria);
            return $results;
        });
    }

    public function publishContent(int $id): ContentResult
    {
        return $this->transaction->execute(function() use ($id) {
            $this->security->enforcePublishPermissions($id);
            
            $content = $this->content->publish($id);
            $this->cache->invalidateContentCache($id);
            $this->logger->logContentPublication($id);
            
            return new ContentResult($content);
        });
    }

    public function unpublishContent(int $id): ContentResult
    {
        return $this->transaction->execute(function() use ($id) {
            $this->security->enforceUnpublishPermissions($id);
            
            $content = $this->content->unpublish($id);
            $this->cache->invalidateContentCache($id);
            $this->logger->logContentUnpublication($id);
            
            return new ContentResult($content);
        });
    }

    public function versionContent(int $id, int $versionId): ContentResult
    {
        return $this->transaction->execute(function() use ($id, $versionId) {
            $this->security->enforceVersionPermissions($id);
            
            $content = $this->content->revertToVersion($id, $versionId);
            $this->cache->invalidateContentCache($id);
            $this->logger->logContentVersioning($id, $versionId);
            
            return new ContentResult($content);
        });
    }

    public function validateContent(array $data): ValidationResult
    {
        try {
            $this->validator->validateContentData($data);
            return new ValidationResult(true);
        } catch (ValidationException $e) {
            $this->logger->logValidationFailure($e->getErrors());
            return new ValidationResult(false, $e->getErrors());
        }
    }

    public function importContent(array $data): ImportResult
    {
        return $this->transaction->execute(function() use ($data) {
            $this->validator->validateImportData($data);
            $this->security->enforceImportPermissions();
            
            $sanitizedData = $this->security->sanitizeInput($data);
            $imported = $this->content->import($sanitizedData);
            
            $this->cache->invalidateContentCache();
            $this->logger->logContentImport(count($imported));
            
            return new ImportResult($imported);
        });
    }

    public function exportContent(array $criteria): ExportResult
    {
        $this->validator->validateExportCriteria($criteria);
        $this->security->enforceExportPermissions();
        
        $sanitizedCriteria = $this->security->sanitizeInput($criteria);
        $exported = $this->content->export($sanitizedCriteria);
        
        $this->logger->logContentExport(count($exported));
        return new ExportResult($exported);
    }
}
