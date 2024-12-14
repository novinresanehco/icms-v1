<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Http\UploadedFile;

interface MediaRepositoryInterface
{
    public function upload(UploadedFile $file, array $data = []): Model;
    public function update(int $id, array $data): Model;
    public function findById(int $id): Model;
    public function getByCategory(string $category): Collection;
    public function search(array $criteria): Collection;
    public function delete(int $id): bool;
}
