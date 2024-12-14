<?php

namespace App\Core\Versioning;

class VersionManager implements VersionManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private DiffGenerator $differ;

    public function createVersion(array $data): VersionResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'version.create',
                'data' => $data
            ]);

            $validated = $this->validator->validate($data, [
                'content' => 'required|array',
                'metadata' => 'array',
                'parent_id' => 'nullable|integer'
            ]);

            $version = $this->repository->create([
                'hash' => $this->generateHash($validated['content']),
                'content' => $validated['content'],
                'metadata' => $validated['metadata'] ?? [],
                'parent_id' => $validated['parent_id'] ?? null,
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);

            $this->cache->tags(['versions'])->put(
                $this->getCacheKey($version->id),
                $version,
                config('cms.cache.ttl')
            );

            DB::commit();
            return new VersionResult($version);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getVersion(int $id): ?VersionResult 
    {
        return $this->cache->tags(['versions'])->remember(
            $this->getCacheKey($id),
            config('cms.cache.ttl'),
            fn() => $this->repository->find($id)
        );
    }

    public function getVersionHistory(int $contentId): array 
    {
        return $this->cache->tags(['versions'])->remember(
            "version_history.{$contentId}",
            config('cms.cache.ttl'),
            fn() => $this->repository->getHistory($contentId)
        );
    }

    public function compareVersions(int $versionA, int $versionB): array 
    {
        $this->security->validateCriticalOperation([
            'action' => 'version.compare',
            'version_a' => $versionA,
            'version_b' => $versionB
        ]);

        $a = $this->getVersion($versionA);
        $b = $this->getVersion($versionB);

        if (!$a || !$b) {
            throw new VersionNotFoundException();
        }

        return $this->differ->generateDiff(
            $a->content,
            $b->content,
            ['detailed' => true]
        );
    }

    public function revertToVersion(int $contentId, int $versionId): VersionResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'version.revert',
                'content_id' => $contentId,
                'version_id' => $versionId
            ]);

            $targetVersion = $this->getVersion($versionId);
            
            if (!$targetVersion) {
                throw new VersionNotFoundException();
            }

            $newVersion = $this->createVersion([
                'content' => $targetVersion->content,
                'metadata' => [
                    'reverted_from' => $versionId,
                    'revert_reason' => 'Manual reversion'
                ],
                'parent_id' => $versionId
            ]);

            DB::commit();
            return $newVersion;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function purgeVersions(int $contentId, ?array $options = []): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'version.purge',
                'content_id' => $contentId
            ]);

            $keepCount = $options['keep_last'] ?? 5;
            $versionsToDelete = $this->repository->getVersionsForPurge($contentId, $keepCount);

            foreach ($versionsToDelete as $version) {
                $this->repository->delete($version->id);
                $this->cache->tags(['versions'])->forget($this->getCacheKey($version->id));
            }

            $this->cache->tags(['versions'])->forget("version_history.{$contentId}");

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function archiveVersions(int $contentId): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'version.archive',
                'content_id' => $contentId
            ]);

            $versions = $this->getVersionHistory($contentId);
            
            foreach ($versions as $version) {
                $this->repository->archive($version->id);
                $this->cache->tags(['versions'])->forget($this->getCacheKey($version->id));
            }

            $this->cache->tags(['versions'])->forget("version_history.{$contentId}");

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function generateHash(array $content): string 
    {
        return hash('sha256', json_encode($content));
    }

    private function getCacheKey(int $id): string 
    {
        return "version.{$id}";
    }
}
