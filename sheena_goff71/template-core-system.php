<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, File};

class TemplateManager
{
    private string $basePath;
    private array $defaultLayout = [
        'header' => true,
        'footer' => true,
        'sidebar' => false
    ];

    public function __construct(string $basePath = 'templates')
    {
        $this->basePath = $basePath;
    }

    public function render(string $template, array $data = [], array $layout = []): string
    {
        $layout = array_merge($this->defaultLayout, $layout);
        
        $cacheKey = "template.{$template}." . md5(serialize($data) . serialize($layout));
        
        return Cache::remember($cacheKey, 3600, function() use ($template, $data, $layout) {
            return $this->compileTemplate($template, $data, $layout);
        });
    }

    private function compileTemplate(string $template, array $data, array $layout): string
    {
        $content = View::make($this->basePath . '.' . $template, $data)->render();
        
        if ($layout['header']) {
            $content = View::make($this->basePath . '.header')->render() . $content;
        }
        
        if ($layout['footer']) {
            $content .= View::make($this->basePath . '.footer')->render();
        }
        
        if ($layout['sidebar']) {
            $sidebar = View::make($this->basePath . '.sidebar')->render();
            $content = "<div class='container'><div class='row'><div class='col-9'>{$content}</div><div class='col-3'>{$sidebar}</div></div></div>";
        }

        return $content;
    }

    public function getAvailableTemplates(): array
    {
        return Cache::remember('available_templates', 3600, function() {
            return collect(File::files(resource_path("views/{$this->basePath}")))
                ->map(fn($file) => $file->getBasename('.blade.php'))
                ->toArray();
        });
    }

    public function getCachedTemplate(string $template): ?string
    {
        return Cache::get("template.{$template}");
    }

    public function clearCache(string $template = null): void
    {
        if ($template) {
            Cache::forget("template.{$template}");
        } else {
            Cache::tags(['templates'])->flush();
        }
    }
}
