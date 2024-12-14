<?php

namespace App\Repositories\Contracts;

use App\Models\Setting;
use Illuminate\Support\Collection;

interface SettingRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get all settings by group
     *
     * @param string $group
     * @return Collection
     */
    public function getByGroup(string $group): Collection;

    /**
     * Get setting value by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getValue(string $key, $default = null): mixed;

    /**
     * Set setting value
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setValue(string $key, mixed $value): bool;

    /**
     * Bulk update settings
     *
     * @param array $settings
     * @return bool
     */
    public function bulkUpdate(array $settings): bool;

    /**
     * Create new setting
     *
     * @param array $data
     * @return Setting
     */
    public function createSetting(array $data): Setting;

    /**
     * Delete setting
     *
     * @param string $key
     * @return bool
     */
    public function deleteSetting(string $key): bool;

    /**
     * Get settings for export
     *
     * @param array $groups
     * @return Collection
     */
    public function getForExport(array $groups = []): Collection;

    /**
     * Import settings
     *
     * @param array $settings
     * @param bool $overwrite
     * @return bool
     */
    public function import(array $settings, bool $overwrite = false): bool;
}
