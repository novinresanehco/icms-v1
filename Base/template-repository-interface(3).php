<?php

namespace App\Repositories\Contracts;

interface TemplateRepositoryInterface
{
    public function find(int $id);
    public function findBySlug(string $slug);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function duplicate(int $id);
    public function getDefault();
    public function setDefault(int $id);
    public function getAvailable();
}
