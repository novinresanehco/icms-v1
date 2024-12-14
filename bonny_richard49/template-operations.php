<?php

namespace App\Core\Template\Operations;

class RenderOperation implements CriticalOperation
{
    private string $template;
    private array $data;
    private RenderConfig $config;

    public function __construct(string $template, array $data, ?RenderConfig $config = null)
    {
        $this->template = $template;
        $this->data = $data;
        $this->config = $config ?? new RenderConfig();
    }

    public function getValidationRules(): array
    {
        return [
            'template' => ['required', 'string', 'exists:templates'],
            'data' => ['required', 'array'],
            'config' => ['required', 'valid_config']
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['template.render', 'content.view'];
    }

    public function getRateLimitKey(): string
    {
        return 'template_render_' . md5($this->template);
    }

    public function execute(): OperationResult
    {
        // Implementation handled by TemplateEngine
    }
}

class ContentRenderOperation implements CriticalOperation
{
    private Content $content;
    private Template $template;

    public function getValidationRules(): array
    {
        return [
            'content' => ['required', 'valid_content'],
            'template' => ['required', 'valid_template']
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.render', 'template.use'];
    }

    public function getSecurityRequirements(): array
    {
        return [
            'xss_protection' => true,
            'html_sanitization' => true,
            'content_verification' => true
        ];
    }
}

class GalleryRenderOperation implements CriticalOperation
{
    private array $media;
    private GalleryConfig $config;

    public function getValidationRules(): array
    {
        return [
            'media' => ['required', 'array', 'valid_media'],
            'config' => ['required', 'valid_gallery_config']
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['media.view', 'gallery.render'];
    }
}

class ComponentRenderOperation implements CriticalOperation
{
    private string $name;
    private array $props;

    public function getValidationRules(): array
    {
        return [
            'name' => ['required', 'string', 'registered_component'],
            'props' => ['required', 'array', 'valid_props']
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['component.render'];
    }

    public function getSecurityRequirements(): array
    {
        return [
            'props_sanitization' => true,
            'xss_prevention' => true,
            'output_encoding' => true
        ];
    }
}
