<?php

namespace App\Core\Plugin\Repository;

use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\DTO\PluginData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface PluginRepositoryInterface extends RepositoryInterface
{
    /**
     * Get active plugins.
     *
     * @return Collection
     */
    public function getActive(): Collection;

    /**
     * Get plugin by identifier.
     *
     * @param string $identifier
     * @return Plugin|null
     */
    public function findByIdentifier(string $identifier): ?Plugin;

    /**
     * Install plugin.
     *
     * @param PluginData $data
     * @return Plugin
     */
    public function install(PluginData $data): Plugin;

    /**
     * Uninstall plugin.
     *
     * @param int $id
     * @return bool
     */
    public function uninstall(int $id): bool;

    /**
     * Enable plugin.
     *
     * @param int $id
     * @return Plugin
     */
    public function enable(int $id): Plugin;

    /**
     * Disable plugin.
     *
     * @param int $id
     * @return Plugin
     */
    public function disable(int $id): Plugin;

    /**
     * Update plugin configuration.
     *
     * @param int $id
     * @param array $config
     * @return Plugin
     */
    public function updateConfig(int $id, array $config): Plugin;

    /**
     * Get plugins by dependency.
     *
     * @param string $dependency
     * @return Collection
     */
    public function getByDependency(string $dependency): Collection;

    /**
     * Check plugin compatibility.
     *
     * @param int $id
     * @return array
     */
    public function checkCompatibility(int $id): array;

    /**
     * Get plugin update information.
     *
     * @param int $id
     * @return array|null
     */
    public function getUpdateInfo(int $id): ?array;

    /**
     * Update plugin to latest version.
     *
     * @param int $id
     * @return Plugin
     */
    public function update(int $id): Plugin;

    /**
     * Get plugin hooks.
     *
     * @param int $id
     * @return array
     */
    public function getHooks(int $id): array;

    /**
     * Run plugin migrations.
     *
     * @param int $id
     * @param string $direction up|down
     * @return bool
     */
    public function runMigrations(int $id, string $direction = 'up'): bool;
}
