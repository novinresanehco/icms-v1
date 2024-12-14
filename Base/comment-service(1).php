<?php

namespace App\Core\Services;

use App\Core\Models\Comment;
use App\Core\Services\Contracts\CommentServiceInterface;
use App\Core\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class CommentService implements CommentServiceInterface
{
    public function __construct(
        private CommentRepositoryInterface $repository
    ) {}

    public function getComment(int $id): ?Comment
    {
        return Cache::tags(['comments'])->remember(
            "comments.{$id}",
            now()->addHour(),
            fn() => $this->repository->findById($id)
        );
    }

    public function getModelComments(string $modelType, int $modelId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getForModel($modelType, $modelId, $perPage);
    }

    public function getLatestComments(int $limit = 10): Collection
    {
        return Cache::tags(['comments'])->remember(
            "comments.latest.{$limit}",
            now()->addMinutes(30),
            fn() => $this->repository->getLatest($limit)
        );
    }

    public function getPendingComments(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getPending($perPage);
    }

    public function getUserComments(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getByUser($userId, $perPage);
    }

    public function createComment(array $data): Comment
    {
        $comment = $this->repository->store($data);
        Cache::tags(['comments'])->flush();
        return $comment;
    }

    public function updateComment(int $id, array $data): Comment
    {
        $comment = $this->repository->update($id, $data);
        Cache::tags(['comments'])->flush();
        return $comment;
    }

    public function approveComment(int $id): bool
    {
        $result = $this->repository->approve($id);
        Cache::tags(['comments'])->flush();
        return $result;
    }

    public function rejectComment(int $id): bool
    {
        $result = $this->repository->reject($id);
        Cache::tags(['comments'])->flush();
        return $result;
    }

    public function deleteComment(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['comments'])->flush();
        return $result;
    }
}
