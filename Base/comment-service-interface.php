<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\Comment;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CommentServiceInterface
{
    public function getComment(int $id): ?Comment;
    
    public function getModelComments(string $modelType, int $modelId, int $perPage = 15): LengthAwarePaginator;
    
    public function getLatestComments(int $limit = 10): Collection;
    
    public function getPendingComments(int $perPage = 15): LengthAwarePaginator;
    
    public function getUserComments(int $userId, int $perPage = 15): LengthAwarePaginator;
    
    public function createComment(array $data): Comment;
    
    public function updateComment(int $id, array $data): Comment;
    
    public function approveComment(int $id): bool;
    
    public function rejectComment(int $id): bool;
    
    public function deleteComment(int $id): bool;
}
