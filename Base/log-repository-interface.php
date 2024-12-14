<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Support\Collection;
use Carbon\Carbon;

interface LogRepositoryInterface
{
    public function getLogFiles(): Collection;
    
    public function getLogContent(string $filename, int $lines = 1000): Collection;
    
    public function searchLogs(array $criteria): Collection;
    
    public function getErrorStats(Carbon $startDate, Carbon $endDate): array;
    
    public function cleanOldLogs(int $daysOld = 30): int;
    
    public function archiveLogs(string $filename): bool;
}
