<?php

namespace App\Core\Widget\Repository;

use App\Core\Widget\Models\Widget;
use App\Core\Widget\DTO\WidgetData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface WidgetRepositoryInterface extends RepositoryInterface
{
    /**
     * Get active widgets by area.
     *
     * @param string $area
     * @return Collection
     */
    public function getByArea(string $area): Collection;

    /**
     * Get widget by identifier.
     *
     * @param string $identifier
     * @return Widget|null
     */
    public function findByIdentifier(string $identifier): ?Widget;

    /**
     * Create widget instance.
     *
     * @param WidgetData $data
     * @return Widget
     */
    public function createWidget(WidgetData $data): Widget;

    /**
     * Update widget instance.
     *
     * @param int $id
     * @param WidgetData $data
     * @return Widget
     */
    public function updateWidget(int $id, WidgetData $data): Widget;

    /**
     * Update widget order.
     *
     * @param string $area
     * @param array $order
     * @return bool
     */
    public function updateOrder(string $area, array $order): bool;

    /**
     * Get widget settings.
     *
     * @param int $id
     * @return array
     */
    public function getSettings(int $id): array;

    /**
     * Get widgets by page.
     *
     * @param int $pageId
     * @return Collection
     */
    public function getByPage(int $pageId): Collection;

    /**
     * Duplicate widget.
     *
     * @param int $id
     * @param array $overrides
     * @return Widget
     */
    public function duplicate(int $id, array $overrides = []): Widget;

    /**
     * Get widget usage statistics.
     *
     * @param int $id
     * @return array
     */
    public function getUsageStats(int $id): array;

    /**
     * Cache widget output.
     *
     * @param int $id
     * @param string $output
     * @param int $ttl
     * @return bool
     */
    public function cacheOutput(int $id, string $output, int $ttl = 3600): bool;

    /**
     * Get cached output.
     *
     * @param int $id
     * @return string|null
     */
    public function getCachedOutput(int $id): ?string;

    /**
     * Get global widgets.
     *
     * @return Collection
     */
    public function getGlobalWidgets(): Collection;

    /**
     * Import widget configuration.
     *
     * @param array $config
     * @return Widget
     */
    public function importWidget(array $config): Widget;
}
