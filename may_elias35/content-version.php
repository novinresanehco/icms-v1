<?php

namespace App\Core\Content\Version;

use App\Core\Security\SecurityManager;
use App\Core\Storage\StorageManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Exceptions\VersionException;

class VersionManager implements VersionInterface
{
    private SecurityManager $security;
    private StorageManager $storage;
    private SystemMonitor $monitor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        StorageManager $storage,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function createVersion(Content $content): Version
    {
        $monitoringId = $this->monitor->startOperation('version_create');
        
        try {
            $this->validateVersionCreation($content);
            
            DB::beginTransaction();
            
            $version = $this->prepareVersion($content);
            $version->save();
            
            $this->storeVersionData($version, $content);
            $this->updateVersionIndex($content);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $version;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new VersionException('Version creation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function restoreVersion(int $versionId): Content
    {
        $monitoringId = $this->monitor->startOperation('version_restore');
        
        try {
            $version = Version::findOrFail($versionId);
            
            $this->validateVersionAccess($version);
            
            DB::beginTransaction();
            
            $content = $this->restoreContent($version);
            $this->createRestorePoint($content);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new VersionException('Version restoration failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateVersionCreation(Content $content): void
    {
        if (!$this->security->validateContentAccess($content, 'version')) {
            throw new VersionException('Access denied');
        }

        if (!$this->validateVersionLimit($content)) {
            throw new VersionException('Version limit exceeded');
        }
    }

    private function validateVersionAccess(Version $version): void
    {
        if (!$this->security->validateVersionAccess($version)) {
            throw new VersionException('Access denied');
        }
    }

    private function prepareVersion(Content $content): Version
    {
        $version = new Version();
        
        $version->content_id = $content->id;
        $version->user_id = auth()->id();
        $version->version_number = $this->getNextVersionNumber($content);
        $version->created_at = now();
        
        return $version;
    }

    private function storeVersionData(Version $version, Content $content): void
    {
        $versionData = $this->prepareVersionData($content);
        
        $this->storage->store(
            $this->getVersionStorageKey($version),
            $versionData,
            ['encrypt' => true]
        );
    }

    private function updateVersionIndex(Content $content): void
    {
        $index = $content->versionIndex ?? new VersionIndex();
        
        $index->content_id = $content->id;
        $index->last_version = $content->versions()->count();
        $index->updated_at = now();
        
        $index->save();
    }

    private function restoreContent(Version $version): Content
    {
        $content = $version->content;
        $versionData = $this->retrieveVersionData($version);
        
        $content->fill($versionData);
        $content->restored_from_version_id = $version->id;
        $content->save();
        
        return $content;
    }

    private function createRestorePoint(Content $content): void
    {
        $this->storage->createBackup(
            "content_restore_{$content->id}_" . time(),
            $content->toArray()
        );
    }

    private function validateVersionLimit(Content $content): bool
    {
        $currentVersions = $content->versions()->count();
        return $currentVersions < $this->config['max_versions'];
    }

    private function getNextVersionNumber(Content $content): int
    {
        return $content->versions()->max('version_number') + 1;
    }

    private function prepareVersionData(Content $content): array
    {
        return array_merge(
            $content->toArray(),
            [
                'version_metadata' => [
                    '