<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Comment;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CommentRepositoryInterface
{
    public function findById(int $id): ?Comment;
    
    public function getForModel(string $modelType, int $modelId, int $perPage = 15): LengthAwarePaginator;
    
    public function getLatest(int $limit = 10): Collection;
    
    public function getPending(int $perPage = 15): LengthAwarePaginator;
    
    public function getByUser(int $userId, int $perPage = 15): LengthAwarePaginator;
    
    public function store(array $data): Comment;
    
    public function update(int $id, array $data): Comment;
    
    public function approve(int $id): bool;
    
    public function reject(int $id): bool;
    
    public function delete(int $id): bool;
}
