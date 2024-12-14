<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Services\CacheService;
use Illuminate\Support\Facades\{View, File};

class TemplateManager
{
    private SecurityManager $security;
    private CacheService $cache;
    private array $registeredComponents = [];

    public function __construct(
        SecurityManager $security,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processTemplateRender($template, $data, $layout),
            ['template' => $template]
        );
    }

    private function processTemplateRender(string $template, array $data, ?string $layout): string
    {
        $cacheKey = "template.{$template}." . md5(serialize($data));

        return $this->cache->remember($cacheKey, function() use ($template, $data, $layout) {
            $content = View::make($template, $this->sanitizeData($data))->render();
            
            if ($layout) {
                $content = View::make($layout, ['content' => $content])->render();
            }

            return $this->processTemplate($content);
        });
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    private function processTemplate(string $content): string
    {
        $content = $this->processComponents($content);
        $content = $this->processSecurity($content);
        return $this->optimizeOutput($content);
    }

    private function processComponents(string $content): string
    {
        foreach ($this->registeredComponents as $tag => $component) {
            $pattern = "/<$tag\b[^>]*>(.*?)<\/$tag>/s";
            $content = preg_replace_callback($pattern, function($matches) use ($component) {
                return $this->renderComponent($component, $matches[1]);
            }, $content);
        }
        return $content;
    }

    private function processSecurity(string $content): string
    {
        $content = $this->security->executeSecureOperation(
            fn() => $this->applySecurityMeasures($content),
            ['content' => $content]
        );
        return $content;
    }

    private function applySecurityMeasures(string $content): string
    {
        $content = $this->removeUnsafeTags($content);
        $content = $this->sanitizeAttributes($content);
        $content = $this->addSecurityHeaders($content);
        return $content;
    }

    private function removeUnsafeTags(string $content): string
    {
        $unsafeTags = ['script', 'iframe', 'object', 'embed'];
        foreach ($unsafeTags as $tag) {
            $content = preg_replace("/<$tag\b[^>]*>.*?<\/$tag>/si", '', $content);
        }
        return $content;
    }

    private function sanitizeAttributes(string $content): string
    {
        return preg_replace_callback('/(<[^>]+)(on\w+\s*=\s*["\'][^"\']*["\'])/i', function($matches) {
            return $matches[1];
        }, $content);
    }

    private function addSecurityHeaders(string $content): string
    {
        if (strpos($content, '<!DOCTYPE html>') !== false) {
            $cspHeader = "<meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'self'; script-src 'self';\">";
            $content = preg_replace('/(<head[^>]*>)/i', "$1$cspHeader", $content);
        }
        return $content;
    }

    private function optimizeOutput(string $content): string
    {
        if (app()->environment('production')) {
            $content = preg_replace('/\s+/', ' ', $content);
            $content = preg_replace('/>\s+</', '><', $content);
        }
        return trim($content);
    }

    public function registerComponent(string $tag, string $class): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Component class $class does not exist");
        }
        $this->registeredComponents[$tag] = $class;
    }

    private function renderComponent(string $class, string $content): string
    {
        $component = new $class();
        return $component->render(['content' => $content]);
    }

    public function clearCache(): void
    {
        $this->cache->flush('template.');
    }
}
