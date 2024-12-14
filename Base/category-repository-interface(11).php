<?php

namespace App\Repositories\Contracts;

interface CategoryRepositoryInterface
{
    public function find(int $id);
    public function findBySlug(string $slug);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function getTree();
    public function getChildren(int $categoryId);
    public function moveToParent(int $categoryId, ?int $parentId);
}
