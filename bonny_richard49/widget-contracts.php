// app/Core/Widget/Contracts/WidgetInterface.php
<?php

namespace App\Core\Widget\Contracts;

interface WidgetInterface
{
    public function getType(): string;
    public function getSettings(): array;
    public function render(): string;
    public function validate(): bool;
}

// app/Core/Widget/Contracts/WidgetFactoryInterface.php 
<?php

namespace App\Core\Widget\Contracts;

use App\Core\Widget\Models\Widget;

interface WidgetFactoryInterface
{
    public function create(array $data): Widget;
}

// app/Core/Widget/Contracts/WidgetRepositoryInterface.php
<?php

namespace App\Core\Widget\Contracts;

use App\Core\Widget\Models\Widget;
use Illuminate\Database\Eloquent\Collection;

interface WidgetRepositoryInterface 
{
    public function find(int $id): ?Widget;
    public function findActive(int $id): ?Widget;
    public function getActiveWidgets(): Collection;
    public function getByType(string $type): Collection;
    public function updateOrder(array $order): bool;
    public function clearCache(): void;
}

// app/Core/Widget/Contracts/WidgetProcessorInterface.php
<?php

namespace App\Core\Widget\Contracts;

use App\Core\Widget\Models\Widget;
use Illuminate\Contracts\Auth\Authenticatable;

interface WidgetProcessorInterface
{
    public function process(Widget $widget, ?Authenticatable $user = null): ?array;
}

// app/Core/Widget/Contracts/WidgetRendererInterface.php
<?php

namespace App\Core\Widget\Contracts;

use App\Core\Widget\Models\Widget;
use Illuminate\Contracts\Auth\Authenticatable;

interface WidgetRendererInterface
{
    public function render(Widget $widget, ?Authenticatable $user = null): string;
    public function renderCollection(iterable $widgets, ?Authenticatable $user = null): string;
}