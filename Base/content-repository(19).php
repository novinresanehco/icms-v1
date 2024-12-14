<?php

namespace App\Repositories\Contracts;

interface ContentRepositoryInterface
{
    public function find(int $id);
    public function findBySlug(string $slug);
    public function getAll(array $filters = [], array $relations = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function restore(int $id);
    public function getByCategory(int $categoryId, array $filters = []);
    public function getByTag(string $tag, array $filters = []);
    public function search(string $term, array $filters = []);
    public function getRevisions(int $contentId);
    public function revertToRevision(int $contentId, int $revisionId);
}
