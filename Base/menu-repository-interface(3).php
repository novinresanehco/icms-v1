<?php

namespace App\Repositories\Contracts;

interface MenuRepositoryInterface
{
    public function find(int $id);
    public function findByLocation(string $location);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function updateItems(int $id, array $items);
    public function getLocations(): array;
    public function duplicate(int $id);
}
