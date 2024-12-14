<?php

namespace App\Core\Settings\Repository;

use App\Core\Settings\Models\Setting;
use App\Core\Settings\DTO\SettingData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface SettingsRepositoryInterface extends RepositoryInterface
{
    /**
     * Get setting by key.
     *
     * @param string $key
     * @return Setting|null
     */
    public function getByKey(string $key): ?Setting;

    /**
     * Get settings by group.
     *
     * @param string $group
     * @return Collection
     */
    public function getByGroup(string $group): Collection;

    /**
     * Get all settings as key-value pairs.
     *
     * @param string|null $group Optional group filter
     * @return array
     */
    public function getAllAsArray(?string $group = null): array;

    /**
     * Set multiple settings at once.
     *
     * @param array $settings Key-value pairs of settings
     * @param string|null $group Optional group for all settings
     * @return bool
     */
    public function setMany(array $settings, ?string $group = null): bool;

    /**
     * Delete settings by group.
     *
     * @param string $group
     * @return bool
     */
    public function deleteByGroup(string $group): bool;

    /**
     * Get settings schema.
     *
     * @param string $group
     * @return array
     */
    public function getSchema(string $group): array;

    /**
     * Validate settings against schema.
     *
     * @param array $settings
     * @param string $group
     * @return array Validation errors
     */
    public function validateAgainstSchema(array $settings, string $group): array;

    /**
     * Register new settings group with schema.
     *
     * @param string $group
     * @param array $schema
     * @param array $defaultValues
     * @return bool
     */
    public function registerGroup(string $group, array $schema, array $defaultValues = []): bool;

    /**
     * Get settings that have been modified since given timestamp.
     *
     * @param string $timestamp
     * @return Collection
     */
    public function getModifiedSince(string $timestamp): Collection;

    /**
     * Export settings to file.
     *
     * @param string $path
     * @param string|null $group Optional group filter
     * @return bool
     */
    public function exportToFile(string $path, ?string $group = null): bool;

    /**
     * Import settings from file.
     *
     * @param string $path
     * @param bool $overwrite Whether to overwrite existing settings
     * @return array Number of settings imported/updated
     */
    public function importFromFile(string $path, bool $overwrite = false): array;
}
