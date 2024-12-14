<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface AuditRepositoryInterface extends RepositoryInterface
{
    public function logActivity(string $action, array $data = []): bool;
    
    public function getUserActivity(int $userId, int $limit = 50): Collection;
    
    public function getRecentActivity(int $limit = 50): Collection;
    
    public function searchActivity(array $criteria): Collection;
    
    public function getActionStats(): Collection;
}
