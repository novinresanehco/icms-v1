<?php

namespace App\Repositories;

use App\Models\Revision;
use App\Repositories\Contracts\RevisionRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class RevisionRepository implements RevisionRepositoryInterface
{
    protected $model;

    public function __construct(Revision $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->with(['content', 'user'])->findOrFail($id);
    }

    public function getAll(array $filters = []): LengthAwarePaginator
    {
        return $this->model->with(['content', 'user'])
            ->when(isset($filters['content_id']), function ($query) use ($filters) {
                return $query->where('content_id', $filters['content_id']);
            })
            ->when(isset($filters['user_id']), function ($query) use ($filters) {
                return $query->where('user_id', $filters['user_id']);
            })
            ->when(isset($filters['date_from']), function ($query) use ($filters) {
                return $query->where('created_at', '>=', $filters['date_from']);
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters) {
                return $query->where('created_at', '<=', $filters['date_to']);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            return $this->model->create(array_merge($data, [
                'user_id' => auth()->id()
            ]));
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            return $this->find($id)->delete();
        });
    }

    public function getByContent(int $contentId): Collection
    {
        return $this->model->with(['user'])
            ->where('content_id', $contentId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getByUser(int $userId): Collection
    {
        return $this->model->with(['content'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function compare(int $revisionId1, int $revisionId2): array
    {
        $revision1 = $this->find($revisionId1);
        $revision2 = $this->find($revisionId2);

        $differences = [];

        foreach (['title', 'content', 'excerpt', 'metadata'] as $field) {
            if ($revision1->{$field} !== $revision2->{$field}) {
                $differences[$field] = [
                    'old' => $revision1->{$field},
                    'new' => $revision2->{$field}
                ];
            }
        }

        return [
            'revision1' => $revision1,
            'revision2' => $revision2,
            'differences' => $differences
        ];
    }

    public function restore(int $revisionId)
    {
        return DB::transaction(function () use ($revisionId) {
            $revision = $this->find($revisionId);
            $content = $revision->content;

            $content->update([
                'title' => $revision->title,
                'content' => $revision->content,
                'excerpt' => $revision->excerpt,
                'metadata' => $revision->metadata
            ]);

            // Create new revision for the restore action
            $this->create([
                'content_id' => $content->id,
                'title' => $revision->title,
                'content' => $revision->content,
                'excerpt' => $revision->excerpt,
                'metadata' => $revision->metadata,
                'notes' => "Restored from revision #{$revisionId}"
            ]);

            return $content->fresh();
        });
    }

    public function getLatestByContent(int $contentId)
    {
        return $this->model->with(['user'])
            ->where('content_id', $contentId)
            ->latest()
            ->first();
    }
}
