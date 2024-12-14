<?php

namespace App\Repositories\Contracts;

interface WidgetRepositoryInterface
{
    public function find(int $id);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function getByArea(string $area);
    public function updateOrder(string $area, array $order);
    public function getAvailableTypes(): array;
    public function duplicate(int $id);
    public function bulkUpdate(array $widgets);
}
