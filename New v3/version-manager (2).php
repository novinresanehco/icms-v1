<?php

namespace App\Core\Content;

class VersionManager implements VersionManagerInterface 
{
    private SecurityManager $security;
    private DatabaseManager $database;
    private StorageManager $storage;
    private CacheManager $cache;
    private AuditService $audit;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        DatabaseManager $database,
        StorageManager $storage,
        CacheManager $cache,
        AuditService $audit,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->database = $database;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->metrics = $metrics;
    }

    public function createVersion(Content $content): Version
    {
        try {
            DB::beginTransaction();

            $this->validateNewVersion($content);
            $version = $this->processVersion($content);
            
            $this->storeVersionData($version);
            $this->updateVersionRelations($version);
            
            $this->database->save($version);
            $this->cache->invalidateVersionCache($content);
            
            $this->audit->logVersionCreation($version);

            DB::commit();
            
            return $version;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'create', $content);
            throw $e;
        }
    }

    public function getVersion(Content $content, string $versionId): Version
    {
        return $this->cache->remember(
            "version:{$content->getId()}:{$versionId}",
            function() use ($content, $versionId) {
                $version = $this->database->findVersion($content, $versionId);
                
                if (!$version) {
                    throw new VersionNotFoundException("Version not found");
                }
                
                return $version;
            }
        );
    }

    public function getVersions(Content $content): VersionCollection
    {
        return $this->cache->remember(
            "versions:{$content->getId()}",
            function() use ($content) {
                return $this->database->getContentVersions($content);
            }
        );
    }

    public function rollback(Content $content, string $versionId, User $user): Content
    {
        try {
            DB::beginTransaction();

            $this->security->validateAccess($user, 'content.rollback');
            $version = $this->getVersion($content, $versionId);
            
            $restoredContent = $this->restoreVersion($content, $version);
            $this->createRollbackVersion($content, $version);
            
            $this->database->save($restoredContent);
            $this->cache->invalidateContentCache($content);
            
            $this->audit->logContentRollback($content, $version, $user);

            DB::commit();
            
            return $restoredContent;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'rollback', $content);
            throw $e;
        }
    }

    public function compareVersions(Version $version1, Version $version2): VersionDiff
    {
        try {
            $diff = new VersionDiff();
            $diff->setChanges($this->calculateDiff($version1, $version2));
            $diff->setMetadata($this->compareMetadata($version1, $version2));
            $diff->setRelations($this->compareRelations($version1, $version2));
            
            return $diff;
            
        } catch (\Exception $e) {
            $this->handleOperationFailure($e, 'compare', [$version1, $version2]);
            throw $e;
        }
    }

    public function pruneVersions(Content $content, VersionPruneStrategy $strategy): void
    {
        try {
            DB::beginTransaction();

            $versions = $this->getVersionsForPruning($content, $strategy);
            
            foreach ($versions as $version) {
                $this->deleteVersion($version);
            }
            
            $this->cache->invalidateVersionCache($content);
            $this->audit->logVersionPruning($content, $strategy);

            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'prune', $content);
            throw $e;
        }
    }

    public function archiveVersions(Content $content): void
    {
        try {
            DB::beginTransaction();

            $versions = $this->getVersions($content);
            
            foreach ($versions as $version) {
                $this->archiveVersion($version);
            }
            
            $this->cache->invalidateVersionCache($content);
            $this->audit->logVersionArchival($content);

            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'archive', $content);
            throw $e;
        }
    }

    private function processVersion(Content $content): Version
    {
        $version = new Version();
        $version->setContent($content);
        $version->setData($this->prepareVersionData($content));
        $version->setMetadata($this->extractVersionMetadata($content));
        $version->setTimestamp(new \DateTime());
        
        return $version;
    }

    private function storeVersionData(Version $version): void
    {
        $data = $version->getData();
        $path = $this->storage->storeVersionData($data);
        $version->setDataPath($path);
    }

    private function updateVersionRelations(Version $version): void
    {
        $relations = $this->extractVersionRelations($version->getContent());
        $version->setRelations($relations);
        
        foreach ($relations as $relation) {
            $this->database->createVersionRelation($version, $relation);
        }
    }

    private function validateNewVersion(Content $content): void
    {
        $latestVersion = $this->getLatestVersion($content);
        
        if ($latestVersion && !$this->hasContentChanged($content, $latestVersion)) {
            throw new NoChangesException('No changes detected since last version');
        }
    }

    private function calculateDiff(Version $version1, Version $version2): array
    {
        $diff = [];
        $data1 = $version1->getData();
        $data2 = $version2->getData();
        
        foreach ($data1 as $key => $value) {
            if (!isset($data2[$key]) || $data2[$key] !== $value) {
                $diff[$key] = [
                    'old' => $value,
                    'new' => $data2[$key] ?? null
                ];
            }
        }
        
        return $diff;
    }

    private function compareMetadata(Version $version1, Version $version2): array
    {
        return array_diff(
            $version1->getMetadata(),
            $version2->getMetadata()
        );
    }

    private function compareRelations(Version $version1, Version $version2): array
    {
        return array_diff(
            $version1->getRelations(),
            $version2->getRelations()
        );
    }

    private function handleOperationFailure(\Exception $e, string $operation, $context): void
    {
        $this->audit->logVersionOperationFailure($operation, $context, $e);
        $this->metrics->recordVersionOperationFailure($operation);
    }
}
