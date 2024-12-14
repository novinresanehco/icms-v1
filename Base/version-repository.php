<?php

namespace App\Repositories;

use App\Models\Version;
use App\Repositories\Contracts\VersionRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class VersionRepository extends BaseRepository implements VersionRepositoryInterface
{
    protected array $searchableFields = ['title', 'description', 'created_by'];
    protected array $filterableFields = ['status', 'type', 'content_id'];

    public function getVersionHistory(int $contentId): Collection
    {
        return $this->model
            ->where('content_id', $contentId)
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function createVersion(array $data, $content): Version
    {
        return $this->create([
            'content_id' => $content->id,
            'title' => $data['title'] ?? "Version " . time(),
            'content' => $content->toJson(),
            'created_by' => auth()->id(),
            'hash' => hash('sha256', $content->toJson()),
            'metadata' => $data['metadata'] ?? [],
            'type' => $data['type'] ?? 'manual'
        ]);
    }

    public function revertTo(int $versionId): bool
    {
        try {
            $version = $this->findById($versionId);
            $content = json_decode($version->content, true);
            
            $contentModel = app('App\Models\Content')->find($version->content_id);
            $contentModel->fill($content);
            $contentModel->save();
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error reverting version: ' . $e->getMessage());
            return false;
        }
    }

    public function compareVersions(int $versionId1, int $versionId2): array
    {
        $version1 = $this->findById($versionId1);
        $version2 = $this->findById($versionId2);

        $content1 = json_decode($version1->content, true);
        $content2 = json_decode($version2->content, true);

        return array_diff_assoc($content1, $content2);
    }
}
