<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\Template;

interface TemplateServiceInterface
{
    public function createTemplate(array $data): Template;
    
    public function updateTemplate(Template $template, array $data): bool;
    
    public function deleteTemplate(Template $template): bool;
    
    public function compileTemplate(Template $template, array $variables = []): string;
    
    public function validateTemplate(string $content): bool;
}
