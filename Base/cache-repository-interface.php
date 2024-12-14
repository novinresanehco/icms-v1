<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CacheRepositoryInterface
{
    public function getStats(): array;
    
    public function clearByTags(array $tags): bool;
    
    public function clearByPattern(string $pattern): int;
    
    public function getKeysByPattern(string $pattern): Collection;
    
    public function warmUp(array $keys): array;
    
    public function getTaggedKeys(array $tags): Collection;
}
