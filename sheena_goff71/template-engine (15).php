<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;

class TemplateEngine 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $registeredTemplates = [];

    public function registerTemplate(string $name, Template $template): void 
    {
        $this->security->validateTemplateAccess($template);
        $this->registeredTemplates[$name] = $template;
    }

    public function render(string $template, array $data): string 
    {
        return $this->cache->remember("template.$template", function() use ($template, $data) {
            return $this->compile($this->registeredTemplates[$template], $data);
        });
    }

    private function compile(Template $template, array $data): string 
    {
        $this->security->validateData($data);
        return $template->compile($data);
    }
}

class ContentDisplay 
{
    private TemplateEngine $engine;
    
    public function displayContent(Content $content): string 
    {
        return $this->engine->render('content', [
            'title' => $content->title,
            'body' => $content->body,
            'metadata' => $content->metadata 
        ]);
    }
}

class MediaGallery
{
    private TemplateEngine $engine;

    public function renderGallery(array $media): string 
    {
        return $this->engine->render('gallery', [
            'items' => array_map(fn($item) => [
                'url' => $item->url,
                'type' => $item->type,
                'title' => $item->title
            ], $media)
        ]);
    }
}

class UIComponents
{
    private TemplateEngine $engine;

    public function renderComponent(string $name, array $props): string 
    {
        return $this->engine->render("components.$name", $props);
    }
}
