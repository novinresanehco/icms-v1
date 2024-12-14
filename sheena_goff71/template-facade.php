<?php

namespace App\Core\Template;

class TemplateFacade implements TemplateFacadeInterface
{
    private TemplateBoundaryGuard $guard;
    private TemplateEngine $engine;
    private ContentRenderer $content;
    private MediaGalleryManager $media;
    private UIComponentRegistry $components;

    public function __construct(
        TemplateBoundaryGuard $guard,
        TemplateEngine $engine,
        ContentRenderer $content,
        MediaGalleryManager $media,
        UIComponentRegistry $components
    ) {
        $this->guard = $guard;
        $this->engine = $engine;
        $this->content = $content;
        $this->media = $media;
        $this->components = $components;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->guard->enforceOperation('template_render', function() use ($template, $data) {
            return $this->engine->render($template, $data);
        });
    }

    public function renderContent(Content $content, array $options = []): string
    {
        return $this->guard->enforceOperation('content_display', function() use ($content, $options) {
            return $this->content->render($content, $options);
        });
    }

    public function renderMedia(array $media, array $options = []): string
    {
        return $this->guard->enforceOperation('media_process', function() use ($media, $options) {
            return $this->media->render($media, $options);
        });
    }

    public function renderComponent(string $name, array $props = []): string
    {
        return $this->guard->enforceOperation('component_render', function() use ($name, $props) {
            return $this->components->render($name, $props);
        });
    }
}

interface TemplateFacadeInterface
{
    public function render(string $template, array $data = []): string;
    public function renderContent(Content $content, array $options = []): string;
    public function renderMedia(array $media, array $options = []): string;
    public function renderComponent(string $name, array $props = []): string;
}
