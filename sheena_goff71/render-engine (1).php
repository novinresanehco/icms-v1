<?php

namespace App\Core\Template\Engine;

class RenderEngine implements RenderEngineInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private array $renderers = [];

    public function render(string $template, array $data): string
    {
        $this->security->validateTemplate($template);
        $cacheKey = "template:{$template}:" . md5(serialize($data));

        return $this->cache->remember($cacheKey, function() use ($template, $data) {
            $content = $this->compile($template, $this->security->sanitizeData($data));
            $this->security->validateOutput($content);
            return $content;
        });
    }

    private function compile(string $template, array $data): string
    {
        $renderer = $this->renderers[$data['type']] ?? $this->renderers['default'];
        return $renderer->compile($template, $data);
    }

    public function registerRenderer(string $type, RendererInterface $renderer): void
    {
        $this->renderers[$type] = $renderer;
    }
}

interface RenderEngineInterface {
    public function render(string $template, array $data): string;
    public function registerRenderer(string $type, RendererInterface $renderer): void;
}

interface RendererInterface {
    public function compile(string $template, array $data): string;
}
