<?php

namespace App\Repositories;

use App\Models\Content;
use App\Models\ContentVersion;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Content();
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function findWithRelations(int $id): ?Content
    {
        return $this->model->with(['metadata', 'tags', 'categories', 'author'])->find($id);
    }

    public function createWithMetadata(array $data, array $metadata): Content
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);
        
        $content = $this->model->create($data);
        $content->metadata()->createMany($metadata);
        
        $this->createVersion($content);
        
        return $content->load('metadata');
    }

    public function updateWithMetadata(int $id, array $data, array $metadata): bool
    {
        $content = $this->model->findOrFail($id);
        
        if (isset($data['title']) && (!isset($data['slug']) || empty($data['slug']))) {
            $data['slug'] = Str::slug($data['title']);
        }
        
        $updated = $content->update($data);
        
        if ($updated) {
            $content->metadata()->delete();
            $content->metadata()->createMany($metadata);
            $this->createVersion($content);
        }
        
        return $updated;
    }

    public function publishContent(int $id): bool
    {
        return $this->model->findOrFail($id)->update([
            'status' => 'published',
            'published_at' => now()
        ]);
    }

    public function unpublishContent(int $id): bool
    {
        return $this->model->findOrFail($id)->update([
            'status' => 'draft',
            'published_at' => null
        ]);
    }

    public function getPublishedContent(): Collection
    {
        return $this->model->published()->with(['metadata', 'author'])->get();
    }

    public function getDraftContent(): Collection
    {
        return $this->model->draft()->with(['metadata', 'author'])->get();
    }

    public function getContentByType(string $type): Collection
    {
        return $this->model->where('type', $type)->with(['metadata', 'author'])->get();
    }

    public function searchContent(string $query): Collection
    {
        return $this->model->where('title', 'like', "%{$query}%")
            ->orWhere('content', 'like', "%{$query}%")
            ->with(['metadata', 'author'])
            ->get();
    }

    public function getContentVersions(int $id): Collection
    {
        return ContentVersion::where('content_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function revertToVersion(int $contentId, int $versionId): bool
    {
        $version = ContentVersion::findOrFail($versionId);
        $content = $this->model->findOrFail($contentId);
        
        $updated = $content->update([
            'content' => $version->content,
            'title' => $version->title
        ]);
        
        if ($updated) {
            $this->createVersion($content);
        }
        
        return $updated;
    }

    public function paginateByStatus(string $status, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->where('status', $status)
            ->with(['metadata', 'author'])
            ->paginate($perPage);
    }

    protected function createVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'created_by' => auth()->id()
        ]);
    }
}
