<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationService;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private array $registeredTemplates = [];
    
    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string 
    {
        // Security validation
        $this->security->validateAccess('template.render');
        $this->validator->validateTemplateData($data);

        return Cache::remember(
            "template.{$template}." . md5(serialize($data)),
            config('template.cache_ttl'),
            function() use ($template, $data) {
                return $this->compile($template, $data);
            }
        );
    }

    public function compile(string $template): string 
    {
        if (!isset($this->registeredTemplates[$template])) {
            throw new TemplateNotFoundException("Template not found: {$template}");
        }

        return $this->registeredTemplates[$template]->compile();
    }

    public function register(string $name, TemplateInterface $template): void 
    {
        // Security validation for template registration
        $this->security->validateAccess('template.register');
        $this->validator->validateTemplate($template);

        $this->registeredTemplates[$name] = $template;
    }

    public function clear(string $template = null): void 
    {
        $this->security->validateAccess('template.clear');
        
        if ($template) {
            Cache::forget("template.{$template}");
        } else {
            Cache::tags(['templates'])->flush();
        }
    }
}

interface TemplateInterface 
{
    public function compile(): string;
    public function validate(): bool;
    public function getCacheKey(): string;
}

class ContentTemplate implements TemplateInterface 
{
    private string $content;
    private array $variables;

    public function compile(): string 
    {
        // Implement template compilation with security checks
        return '';
    }

    public function validate(): bool 
    {
        // Implement validation logic
        return true;
    }

    public function getCacheKey(): string 
    {
        return md5($this->content . serialize($this->variables));
    }
}

class TemplateRenderer 
{
    private TemplateManager $manager;
    private SecurityManagerInterface $security;

    public function renderContent(string $template, array $data): string 
    {
        $this->security->validateAccess('template.render');
        
        // Performance optimization with caching
        return Cache::remember(
            "rendered.{$template}." . md5(serialize($data)),
            config('template.render_ttl'),
            function() use ($template, $data) {
                return $this->manager->render($template, $data);
            }
        );
    }

    public function renderMedia(array $media): string 
    {
        $this->security->validateAccess('media.render');
        
        return $this->manager->render('media-gallery', [
            'items' => $media
        ]);
    }
}
