<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaRepositoryInterface
{
    public function find(int $id): ?Media;
    
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    
    public function create(array $data): Media;
    
    public function update(int $id, array $data): Media;
    
    public function delete(int $id): bool;
    
    public function getByType(string $type): Collection;
    
    public function getByUser(int $userId): Collection;
    
    public function getByMimeType(string $mimeType): Collection;
    
    public function createFromUpload(array $fileData): Media;
    
    public function getVariants(int $mediaId): Collection;
    
    public function attachToContent(int $mediaId, int $contentId, string $type = 'attachment'): bool;
    
    public function detachFromContent(int $mediaId, int $contentId): bool;
}
