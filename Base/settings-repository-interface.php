<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface SettingsRepositoryInterface
{
    /**
     * Get all settings
     *
     * @param bool $useCache
     * @return Collection
     */
    public function all(bool $useCache = true): Collection;

    /**
     * Get setting by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Get settings by group
     *
     * @param string $group
     * @return Collection
     */
    public function getGroup(string $group): Collection;

    /**
     * Set setting value
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @param string|null $group
     * @return bool
     */
    public function set(string $key, mixed $value, string $type = 'string', ?string $group = null): bool;

    /**
     * Set multiple settings at once
     *
     * @param array $settings
     * @return bool
     */
    public function setMany(array $settings): bool;

    /**
     * Delete setting
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Delete settings by group
     *
     * @param string $group
     * @return bool
     */
    public function deleteGroup(string $group): bool;

    /**
     * Clear settings cache
     *
     * @return void
     */
    public function clearCache(): void;
}
