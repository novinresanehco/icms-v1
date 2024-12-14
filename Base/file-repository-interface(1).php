<?php

namespace App\Core\Repositories\Contracts;

use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;

interface FileRepositoryInterface extends RepositoryInterface
{
    public function store(UploadedFile $file, array $options = []): File;
    
    public function getByFolder(?int $folderId = null): Collection;
    
    public function getByType(string $type): Collection;
    
    public function duplicate(int $fileId, ?int $targetFolderId = null): ?File;
    
    public function move(int $fileId, int $targetFolderId): bool;
    
    public function updateVisibility(int $fileId, string $visibility): bool;
    
    public function getFileUrl(int $fileId, int $expirationMinutes = 5): ?string;
    
    public function getRecentFiles(int $limit = 10): Collection;
}
