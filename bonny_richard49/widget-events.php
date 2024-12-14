// app/Core/Widget/Events/WidgetCreated.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class WidgetCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Widget $widget
    ) {}
}

// app/Core/Widget/Events/WidgetUpdated.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class WidgetUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Widget $widget
    ) {}
}

// app/Core/Widget/Events/WidgetDeleted.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class WidgetDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Widget $widget
    ) {}
}

// app/Core/Widget/Events/WidgetRendered.php
<?php

namespace App\Core\Widget\Events;

use App\Core\Widget\Models\Widget;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class WidgetRendered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Widget $widget,
        public array $data
    ) {}
}