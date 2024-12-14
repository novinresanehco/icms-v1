<?php

namespace App\Repositories\Contracts;

interface WidgetAreaRepositoryInterface
{
    public function find(int $id);
    public function findBySlug(string $slug);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function getActive();
    public function activate(int $id);
    public function deactivate(int $id);
}
