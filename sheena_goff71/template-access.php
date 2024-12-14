<?php

namespace App\Core\Template\Security;

class TemplateAccessControl
{
    private array $allowedOperations = [
        'template.render',
        'content.display', 
        'media.render',
        'component.render'
    ];

    public function validateOperation(string $operation): bool
    {
        return in_array($operation, $this->allowedOperations);
    }

    public function validateTemplate(Template $template): void
    {
        if (!$template->validate()) {
            throw new TemplateForbiddenException();
        }
    }
}
