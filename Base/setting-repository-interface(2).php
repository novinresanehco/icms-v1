<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Support\Collection;

interface SettingRepositoryInterface
{
    /**
     * Get a setting value by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Set a setting value
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set(string $key, $value): bool;

    /**
     * Check if setting exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a setting
     *
     * @param string $key
     * @return bool
     */
    public function remove(string $key): bool;

    /**
     * Get all settings by group
     *
     * @param string $group
     * @return \Illuminate\Support\Collection
     */
    public function getAllByGroup(string $group): Collection;

    /**
     * Get all settings
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAll(): Collection;

    /**
     * Set multiple settings at once
     *
     * @param array $settings
     * @return bool
     */
    public function setMany(array $settings): bool;

    /**
     * Remove multiple settings at once
     *
     * @param array $keys
     * @return bool
     */
    public function removeMany(array $keys): bool;

    /**
     * Remove all settings in a group
     *
     * @param string $group
     * @return bool
     */
    public function removeByGroup(string $group): bool;
}
