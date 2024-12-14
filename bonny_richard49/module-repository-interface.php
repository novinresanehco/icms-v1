<?php

namespace App\Core\Module\Repository;

use App\Core\Module\Models\Module;
use App\Core\Module\DTO\ModuleData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface ModuleRepositoryInterface extends RepositoryInterface
{
    /**
     * Get active modules.
     *
     * @return Collection
     */
    public function getActive(): Collection;

    /**
     * Find module by identifier.
     *
     * @param string $identifier
     * @return Module|null
     */
    public function findByIdentifier(string $identifier): ?Module;

    /**
     * Install module.
     *
     * @param ModuleData $data
     * @return Module
     */
    public function install(ModuleData $data): Module;

    /**
     * Uninstall module.
     *
     * @param int $id
     * @return bool
     */
    public function uninstall(int $id): bool;

    /**
     * Enable module.
     *
     * @param int $id
     * @return Module
     */
    public function enable(int $id): Module;

    /**
     * Disable module.
     *
     * @param int $id
     * @return Module
     */
    public function disable(int $id): Module;

    /**
     * Update module configuration.
     *
     * @param int $id
     * @param array $config
     * @return Module
     */
    public function updateConfig(int $id, array $config): Module;

    /**
     * Get modules registered for a specific hook.
     *
     * @param string $hook
     * @return Collection
     */
    public function getModulesByHook(string $hook): Collection;

    /**
     * Get module dependencies.
     *
     * @param int $id
     * @return array
     */
    public function getDependencies(int $id): array;

    /**
     * Check if module is compatible with current system.
     *
     * @param int $id
     * @return array
     */
    public function checkCompatibility(int $id): array;

    /**
     * Run module migrations.
     *
     * @param int $id
     * @param string $direction up|down
     * @return bool
     */
    public function runMigrations(int $id, string $direction = 'up'): bool;

    /**
     * Get module services.
     *
     * @param int $id
     * @return array
     */
    public function getServices(int $id): array;

    /**
     * Get module routes.
     *
     * @param int $id
     * @return array
     */
    public function getRoutes(int $id): array;
}
