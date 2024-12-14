<?php

namespace App\Repositories\Contracts;

interface TagRepositoryInterface
{
    public function find(int $id);
    public function findBySlug(string $slug);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function findOrCreateMany(array $tags);
    public function getPopular(int $limit = 10);
    public function getRelated(int $tagId, int $limit = 5);
}
