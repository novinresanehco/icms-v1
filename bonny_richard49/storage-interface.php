<?php

namespace App\Core\Interfaces;

interface StorageServiceInterface
{
    public function get(string $path): string;
    public function put(string $path, string $contents): bool;
    public function putFile(string $path, $file): string;
    public function delete(string $path): bool;
    public function exists(string $path): bool;
    public function size(string $path): int;
    public function lastModified(string $path): int;
}
