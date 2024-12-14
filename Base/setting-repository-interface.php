<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Setting;
use Illuminate\Support\Collection;

interface SettingRepositoryInterface
{
    /**
     * Get settings by group
     *
     * @param string $group
     * @return Collection
     */
    public function getByGroup(string $group): Collection;

    /**
     * Get setting value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getValue(string $key, mixed $default = null): mixed;

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
     * @param array $values
     * @return bool
     */
    public function bulkUpdate(array $values): bool;

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
    public function getForExport(array $groups): Collection;

    /**
     * Import settings
     *
     * @param array $settings
     * @param bool $overwrite
     * @return bool
     */
    public function import(array $settings, bool $overwrite = true): bool;
}