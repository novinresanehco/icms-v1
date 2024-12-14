<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Content;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContentRepositoryInterface
{
    public function find(int $id): ?Content;
    public function findBySlug(string $slug): ?Content;
    public function all(array $filters = []): Collection;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function create(array $data): Content;
    public function update(Content $content, array $data): bool;
    public function delete(Content $content): bool;
    public function getByType(string $type): Collection;
    public function getByStatus(string $status): Collection;
    public function getPublished(): Collection;
    public function getByAuthor(int $authorId): Collection;
    public function getVersions(int $contentId): Collection;
}
