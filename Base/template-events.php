<?php

namespace App\Core\Events;

use App\Core\Models\Template;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TemplateCreated
{
    use Dispatchable, SerializesModels;

    public Template $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }
}

class TemplateUpdated
{
    use Dispatchable, SerializesModels;

    public Template $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }
}

class TemplateDeleted
{
    use Dispatchable, SerializesModels;

    public Template $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }
}
