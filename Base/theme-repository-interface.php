<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Theme;
use Illuminate\Support\Collection;

interface ThemeRepositoryInterface
{
    /**
     * Find theme by ID
     *
     * @param int $id
     * @return Theme|null
     */
    public function findById(int $id): ?Theme;

    /**
     * Find theme by slug
     *
     * @param string $slug
     * @return Theme|null
     */
    public function findBySlug(string $slug): ?Theme;

    /**
     * Get active theme
     *
     * @return Theme|null
     */
    public function getActive(): ?Theme;

    /**
     * Create new theme
     *
     * @param array $data
     * @return Theme
     */
    public function create(array $data): Theme;

    /**
     * Update theme
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete theme
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get all themes
     *
     * @return Collection
     */
    public function getAll(): Collection;

    /**
     * Activate theme
     *
     * @param int $id
     * @return bool
     */
    public function activate(int $id): bool;

    /**
     * Update theme customization
     *
     * @param int $themeId
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function updateCustomization(int $themeId, string $key, mixed $value): bool;
}