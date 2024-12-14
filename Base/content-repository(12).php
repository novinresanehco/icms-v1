<?php

namespace App\Core\Repositories;

use App\Core\Models\Content;
use App\Core\Models\ContentVersion;
use App\Core\Exceptions\ContentNotFoundException;
use App\Core\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ContentRepository implements ContentRepositoryInterface
{
    public function __construct(
        private Content $model,
        private ContentVersion $versionModel
    ) {}

    public function findById(int $id): ?Content
    {
        try {
            return $this->model->with(['template', 'meta', 'author'])->findOrFail($id);
        } catch (ModelNotFoundException) {
            throw new ContentNotFoundException("Content with ID {$id} not found");
        }
    }

    public function findBySlug(string $slug): ?Content
    {
        try {
            return $this->model->with(['template', 'meta', 'author'])
                ->where('slug', $slug)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ContentNotFoundException("Content with slug {$slug} not found");
        }
    }

    public function findByType(string $type, array $options = []): LengthAwarePaginator
    {
        $query = $this->model->with(['template', 'meta', 'author'])
            ->where('type', $type);

        if (!empty($options['status'])) {
            $query->where('status', $options['status']);
        }

        return $query->orderBy($options['sort'] ?? 'created_at', $options['order'] ?? 'desc')
            ->paginate($options['perPage'] ?? 15);
    }

    public function getPublished(array $options = []): LengthAwarePaginator
    {
        $query = $this->model->with(['template', 'meta', 'author'])
            ->where('status', 'published')
            ->where('published_at', '<=', now());

        if (!empty($options['type'])) {
            $query->where('type', $options['type']);
        }

        if (!empty($options['category'])) {
            $query->whereHas('categories', function ($q) use ($options) {
                $q->where('slug', $options['category']);
            });
        }

        return $query->orderBy($options['sort'] ?? 'published_at', $options['order'] ?? 'desc')
            ->paginate($options['perPage'] ?? 15);
    }

    public function store(array $data): Content
    {
        $content = $this->model->create($data);
        
        if (!empty($data['meta'])) {
            $content->meta()->createMany($data['meta']);
        }
        
        $this->createVersion($content, 'created');
        
        return $content->load(['template', 'meta', 'author']);
    }

    public function update(int $id, array $data): ?Content
    {
        $content = $this->findById($id);
        $content->update($data);

        if (!empty($data['meta'])) {
            $content->meta()->delete();
            $content->meta()->createMany($data['meta']);
        }

        $this->createVersion($content, 'updated');

        return $content->fresh(['template', 'meta', 'author']);
    }

    public function delete(int $id): bool
    {
        $content = $this->findById($id);
        $content->meta()->delete();
        return (bool) $content->delete();
    }

    public function publish(int $id): bool
    {
        $content = $this->findById($id);
        return (bool) $content->update([
            'status' => 'published',
            'published_at' => now()
        ]);
    }

    public function unpublish(int $id): bool
    {
        $content = $this->findById($id);
        return (bool) $content->update([
            'status' => 'draft',
            'published_at' => null
        ]);
    }

    public function getVersions(int $id): Collection
    {
        return $this->versionModel
            ->where('content_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function revertToVersion(int $id, int $versionId): ?Content
    {
        $content = $this->findById($id);
        $version = $this->versionModel->findOrFail($versionId);

        $content->update($version->content_data);
        $this->createVersion($content, 'reverted');

        return $content->fresh(['template', 'meta', 'author']);
    }

    private function createVersion(Content $content, string $reason): void
    {
        $this->versionModel->create([
            'content_id' => $content->id,
            'content_data' => $content->toArray(),
            'user_id' => auth()->id(),
            'reason' => $reason
        ]);
    }
}
