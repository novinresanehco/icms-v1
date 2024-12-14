<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface CommentRepositoryInterface
{
    public function create(array $data): ?int;
    
    public function update(int $commentId, array $data): bool;
    
    public function delete(int $commentId): bool;
    
    public function get(int $commentId): ?array;
    
    public function getForContent(int $contentId, int $perPage = 15): LengthAwarePaginator;
    
    public function getRecent(int $limit = 10): Collection;
    
    public function approve(int $commentId): bool;
    
    public function reject(int $commentId): bool;
    
    public function markAsSpam(int $commentId): bool;
    
    public function getUnapproved(int $perPage = 15): LengthAwarePaginator;
    
    public function getSpam(int $perPage = 15): LengthAwarePaginator;
    
    public function replyTo(int $parentId, array $data): ?int;
}
