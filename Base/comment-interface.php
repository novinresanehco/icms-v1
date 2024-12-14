<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};

interface CommentRepositoryInterface
{
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function updateStatus(int $id, string $status): Model;
    public function findById(int $id): Model;
    public function getContentComments(int $contentId, bool $threaded = true): Collection;
    public function getPendingComments(): Collection;
    public function getRecentComments(int $limit = 10): Collection;
    public function getUserComments(int $userId): Collection;
    public function searchComments(array $criteria): Collection;
    public function delete(int $id): bool;
    public function markAsSpam(int $id): void;
}
