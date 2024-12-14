<?php

namespace App\Repositories\Contracts;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface FileRepositoryInterface
{
    public function store(UploadedFile $file, string $path = ''): ?string;
    
    public function storeAs(UploadedFile $file, string $name, string $path = ''): ?string;
    
    public function delete(string $path): bool;
    
    public function get(string $path): ?array;
    
    public function getAllInDirectory(string $directory): Collection;
    
    public function move(string $from, string $to): bool;
    
    public function copy(string $from, string $to): bool;
    
    public function exists(string $path): bool;
    
    public function size(string $path): int;
    
    public function lastModified(string $path): int;
    
    public function getUrl(string $path): string;
    
    public function getMimeType(string $path): ?string;
}
