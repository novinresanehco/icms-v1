<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Widget;
use Illuminate\Support\Collection;

interface WidgetRepositoryInterface
{
    /**
     * Find widget by ID
     *
     * @param int $id
     * @return Widget|null
     */
    public function findById(int $id): ?Widget;

    /**
     * Find widget by key
     *
     * @param string $key
     * @return Widget|null
     */
    public function findByKey(string $key): ?Widget;

    /**
     * Create new widget
     *
     * @param array $data
     * @return Widget
     */
    public function create(array $data): Widget;

    /**
     * Update widget
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete widget
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get all widgets
     *
     * @return Collection
     */
    public function getAll(): Collection;

    /**
     * Get widgets by position
     *
     * @param string $position
     * @return Collection
     */
    public function getByPosition(string $position): Collection;

    /**
     * Get widgets by type
     *
     * @param string $type
     * @return Collection
     */
    public function getByType(string $type): Collection;

    /**
     * Update widget positions and order
     *
     * @param array $positions
     * @return bool
     */
    public function updateOrder(array $positions): bool;

    /**
     * Get available widget positions
     *
     * @return Collection
     */
    public function getPositions(): Collection;
}