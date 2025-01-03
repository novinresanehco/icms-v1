<?php

namespace App\Core\Media;

use Illuminate\Http\UploadedFile;
use App\Core\Security\SecurityContext;

interface MediaManagerInterface
{
    public function upload(UploadedFile $file, array $metadata, SecurityContext $context): Media;
    public function delete(int $mediaId, SecurityContext $context): void;
    public function get(int $mediaId, SecurityContext $context): Media;
    public function update(int $mediaId, array $metadata, SecurityContext $context): Media;
}

interface StorageManagerInterface
{
    public function store(UploadedFile $file): string;
    public function delete(string $path): void;
    public function cleanup(?string $path): void;
}

interface MediaRepositoryInterface
{
    public function findById(int $id): ?Media;
    public function create(array $data): Media;
    public function update(int $id, array $data): Media;
    public function delete(int $id): void;
}
