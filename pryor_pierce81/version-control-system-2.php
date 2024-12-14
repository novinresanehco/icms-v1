<?php

namespace App\Core\Version;

use Illuminate\Support\Facades\{DB, Cache, Storage};
use App\Core\Security\SecurityManager;
use App\Core\Content\ContentEntity;

class VersionManager implements VersionInterface
{
    protected SecurityManager $security;
    protected VersionRepository $repository;
    protected DiffGenerator $differ;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        VersionRepository $repository,
        DiffGenerator $differ,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->differ = $differ;
        $this->config = $config;
    }

    public function createVersion(ContentEntity $content): Version
    {
        return $this->security->executeCriticalOperation(function() use ($content) {
            return DB::transaction(function() use ($content) {
                $previousVersion = $this->getLatestVersion($content->id);
                $versionNumber = $previousVersion ? $previousVersion->number + 1 : 1;
                
                $version = $this->repository->create([
                    'content_id' => $content->id,
                    'number' => $versionNumber,
                    'data' => $this->serializeContent($content),
                    'hash' => $this->generateHash($content),
                    'user_id' => auth()->id(),
                    'metadata' => $this->generateMetadata($content, $previousVersion)
                ]);

                if ($previousVersion) {
                    $this->storeDiff($version, $previousVersion);
                }

                $this->cleanupOldVersions($content->id);
                return $version;
            });
        });
    }

    public function restoreVersion(ContentEntity $content, int $versionNumber): ContentEntity
    {
        return $this->security->executeCriticalOperation(function() use ($content, $versionNumber) {
            return DB::transaction(function() use ($content, $versionNumber) {
                $version = $this->repository->findVersion($content->id, $versionNumber);
                
                if (!$version) {
                    throw new VersionNotFoundException("Version {$versionNumber} not found");
                }

                $restoredData = $this->unserializeContent($version->data);
                $this->createVersion($content); // Backup current state
                
                return new ContentEntity($restoredData);
            });
        });
    }

    public function getVersionHistory(int $contentId): array
    {
        return $this->security->executeCriticalOperation(function() use ($contentId) {
            $cacheKey = "version_history.{$contentId}";
            
            return Cache::tags(['versions'])->remember($cacheKey, function() use ($contentId) {
                return $this->repository->getVersions($contentId);
            });
        });
    }

    public function compareVersions(int $contentId, int $fromVersion, int $toVersion): array
    {
        return $this->security->executeCriticalOperation(function() use ($contentId, $fromVersion, $toVersion) {
            $from = $this->repository->findVersion($contentId, $fromVersion);
            $to = $this->repository->findVersion($contentId, $toVersion);
            
            return $this->differ->generate(
                $this->unserializeContent($from->data),
                $this->unserializeContent($to->data)
            );
        });
    }

    protected function getLatestVersion(int $contentId): ?Version
    {
        return $this->repository->getLatestVersion($contentId);
    }

    protected function serializeContent(ContentEntity $content): string
    {
        $data = $content->toArray();
        ksort($data); // Ensure consistent serialization
        return json_encode($data);
    }

    protected function unserializeContent(string $data): array
    {
        $content = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new VersionCorruptedException('Failed to unserialize version data');
        }
        return $content;
    }

    protected function generateHash(ContentEntity $content): string
    {
        return hash('sha256', $this->serializeContent($content));
    }

    protected function generateMetadata(ContentEntity $content, ?Version $previousVersion): array
    {
        return [
            'timestamp' => now()->timestamp,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'changes' => $previousVersion ? 
                $this->summarizeChanges($content, $previousVersion) : 
                ['type' => 'initial_version']
        ];
    }

    protected function summarizeChanges(ContentEntity $content, Version $previousVersion): array
    {
        $diff = $this->differ->generate(
            $this->unserializeContent($previousVersion->data),
            $content->toArray()
        );

        return [
            'type' => 'update',
            'changed_fields' => array_keys($diff),
            'change_count' => count($diff)
        ];
    }

    protected function storeDiff(Version $newVersion, Version $previousVersion): void
    {
        $diff = $this->differ->generate(
            $this->unserializeContent($previousVersion->data),
            $this->unserializeContent($newVersion->data)
        );

        Storage::put(
            $this->getDiffPath($newVersion),
            json_encode($diff)
        );
    }

    protected function getDiffPath(Version $version): string
    {
        return "versions/diffs/{$version->content_id}/{$version->number}.diff";
    }

    protected function cleanupOldVersions(int $contentId): void
    {
        $versions = $this->repository->getVersions($contentId);
        $keepVersions = $this->config['versions_to_keep'];
        
        if (count($versions) > $keepVersions) {
            $versionsToDelete = array_slice($versions, $keepVersions);
            
            foreach ($versionsToDelete as $version) {
                Storage::delete($this->getDiffPath($version));
                $this->repository->delete($version->id);
            }
        }
    }
}
