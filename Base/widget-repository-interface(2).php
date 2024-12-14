<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Widget;
use Illuminate\Database\Eloquent\Collection;

interface WidgetRepositoryInterface extends RepositoryInterface
{
    public function getActiveWidgets(string $area): Collection;
    
    public function updateWidgetOrder(array $order): bool;
    
    public function createWidget(array $data): Widget;
    
    public function getWidgetsByType(string $type): Collection;
    
    public function updateWidgetSettings(int $id, array $settings): bool;
}
