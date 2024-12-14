<?php

namespace App\Services;

use App\Interfaces\SecurityServiceInterface;
use App\Models\{Content, ContentVersion, ContentRevision};
use Illuminate\Support\Facades\{DB, Cache};
use App\Exceptions\VersionControlException;

class VersionControlService
{
    private SecurityServiceInterface $security;
    private CacheService $cache;

    public function __construct(
        SecurityServiceInterface $security,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function createVersion(Content $content): ContentVersion
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeCreateVersion($content),
            ['action' => 'version.create', 'permission' => 'content.version']
        );
    }

    private function executeCreateVersion(Content $content): ContentVersion
    {
        return DB::transaction(function() use ($content) {
            $version = ContentVersion::create([
                'content_id' => $content->id,
                'version_number' => $this->getNextVersionNumber($content),
                'created_by' => auth()->id(),
                'title' => $content->title,
                'status' => $content->status
            ]);

            $this->createRevision($version, $content->toArray());
            $this->invalidateCache($content->id);

            return $version;
        });
    }

    public function compareVersions(int $versionId1, int $versionId2): array
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeCompareVersions($versionId1, $versionId2),
            ['action' => 'version.compare', 'permission' => 'content.view']
        );
    }

    private function executeCompareVersions(int $versionId1, int $versionId2): array
    {
        $version1 = ContentVersion::with('revision')->findOrFail($versionId1);
        $version2 = ContentVersion::with('revision')->findOrFail($versionId2);

        if ($version1->content_id !== $version2->content_id) {
            throw new VersionControlException('Cannot compare versions from different content');
        }

        return [
            'metadata' => $this->compareMetadata($version1, $version2),
            'content' => $this->compareContent(
                $version1->revision->content_data,
                $version2->revision->content_data
            )
        ];
    }

    public function revertToVersion(Content $content, int $versionId): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeRevertToVersion($content, $versionId),
            ['action' => 'version.revert', 'permission' => 'content.version']
        );
    }

    private function executeRevertToVersion(Content $content, int $versionId): Content
    {
        return DB::transaction(function() use ($content, $versionId) {
            $version = ContentVersion::with('revision')
                ->where('content_id', $content->id)
                ->findOrFail($versionId);

            // Create new version before reverting
            $this->createVersion($content);

            // Revert content
            $contentData = $version->revision->content_data;
            $content->update($contentData);

            $this->invalidateCache($content->id);

            return $content->fresh();
        });
    }

    public function getVersionHistory(Content $content): array
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeGetVersionHistory($content),
            ['action' => 'version.history', 'permission' => 'content.view']
        );
    }

    private function executeGetVersionHistory(Content $content): array
    {
        $cacheKey = "content:{$content->id}:history";

        return $this->cache->remember($cacheKey, function() use ($content) {
            return ContentVersion::with(['revision', 'creator'])
                ->where('content_id', $content->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($version) {
                    return [
                        'id' => $version->id,
                        'version_number' => $version->version_number,
                        'created_at' => $version->created_at,
                        'created_by' => [
                            'id' => $version->creator->id,
                            'name' => $version->creator->name
                        ],
                        'title' => $version->title,
                        'status' => $version->status,
                        'changes' => $this->summarizeChanges($version)
                    ];
                })
                ->toArray();
        }, 3600);
    }

    private function getNextVersionNumber(Content $content): int
    {
        return ContentVersion::where('content_id', $content->id)
            ->max('version_number') + 1;
    }

    private function createRevision(ContentVersion $version, array $data): ContentRevision
    {
        return ContentRevision::create([
            'version_id' => $version->id,
            'content_data' => json_encode($data),
            'checksum' => $this->calculateChecksum($data)
        ]);
    }

    private function compareMetadata(ContentVersion $v1, ContentVersion $v2): array
    {
        return [
            'title' => [
                'old' => $v1->title,
                'new' => $v2->title,
                'changed' => $v1->title !== $v2->title
            ],
            'status' => [
                'old' => $v1->status,
                'new' => $v2->status,
                'changed' => $v1->status !== $v2->status
            ],
            'created_at' => [
                'old' => $v1->created_at,
                'new' => $v2->created_at
            ],
            'created_by' => [
                'old' => $v1->creator->name,
                'new' => $v2->creator->name
            ]
        ];
    }

    private function compareContent(string $content1, string $content2): array
    {
        $data1 = json_decode($content1, true);
        $data2 = json_decode($content2, true);

        $changes = [];
        foreach ($data2 as $key => $value) {
            if (!isset($data1[$key])) {
                $changes[$key] = [
                    'type' => 'added',
                    'new' => $value
                ];
            } elseif ($data1[$key] !== $value) {
                $changes[$key] = [
                    'type' => 'modified',
                    'old' => $data1[$key],
                    'new' => $value
                ];
            }
        }

        foreach ($data1 as $key => $value) {
            if (!isset($data2[$key])) {
                $changes[$key] = [
                    'type' => 'removed',
                    'old' => $value
                ];
            }
        }

        return $changes;
    }

    private function summarizeChanges(ContentVersion $version): array
    {
        $previousVersion = ContentVersion::with('revision')
            ->where('content_id', $version->content_id)
            ->where('version_number', '<', $version->version_number)
            ->orderBy('version_number', 'desc')
            ->first();

        if (!$previousVersion) {
            return ['type' => 'initial_version'];
        }

        return $this->compareContent(
            $previousVersion->revision->content_data,
            $version->revision->content_data
        );
    }

    private function calculateChecksum(array $data): string
    {
        return hash('sha256', json_encode($data));
    }

    private function invalidateCache(int $contentId): void
    {
        $this->cache->tags(['content', "content:{$contentId}"])->flush();
    }
}
