<?php

namespace App\Core\Template;

use App\Core\Security\{SecurityContext, CoreSecurityManager};
use App\Core\Cache\CachePerformanceManager;
use Illuminate\Support\Facades\{View, DB};

class TemplateManager implements TemplateInterface
{
    private CoreSecurityManager $security;
    private CachePerformanceManager $cache;
    private ValidationService $validator;
    private CompilerService $compiler;
    private ThemeManager $themeManager;
    private MetricsCollector $metrics;

    public function __construct(
        CoreSecurityManager $security,
        CachePerformanceManager $cache,
        ValidationService $validator,
        CompilerService $compiler,
        ThemeManager $themeManager,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->compiler = $compiler;
        $this->themeManager = $themeManager;
        $this->metrics = $metrics;
    }

    public function render(string $template, array $data, SecurityContext $context): string
    {
        return $this->security->executeCriticalOperation(
            new TemplateOperation('render', ['template' => $template, 'data' => $data]),
            $context,
            function() use ($template, $data) {
                $this->validator->validateTemplate($template);
                $validated = $this->validator->validateData($data);
                
                $cacheKey = $this->getCacheKey($template, $validated);
                
                return $this->cache->remember($cacheKey, function() use ($template, $validated) {
                    $compiled = $this->compile($template);
                    return $this->renderCompiled($compiled, $validated);
                });
            }
        );
    }

    public function compile(string $template): CompiledTemplate
    {
        return $this->compiler->compile(
            $this->loadTemplate($template),
            $this->getCompilerOptions()
        );
    }

    public function renderContent(Content $content, array $options = []): string
    {
        return $this->render(
            $content->template ?? $this->getDefaultTemplate(),
            $this->prepareContentData($content, $options),
            new SecurityContext(['content_id' => $content->id])
        );
    }

    public function renderPartial(string $partial, array $data = []): string
    {
        return $this->render(
            "partials.{$partial}",
            $data,
            new SecurityContext(['partial' => true])
        );
    }

    public function registerExtension(string $name, callable $extension): void
    {
        $this->security->executeCriticalOperation(
            new TemplateOperation('register_extension', ['name' => $name]),
            new SecurityContext(['system' => true]),
            function() use ($name, $extension) {
                $this->validator->validateExtensionName($name);
                $this->compiler->registerExtension($name, $extension);
                $this->cache->invalidateTag('templates');
            }
        );
    }

    public function setTheme(string $theme): void
    {
        $this->security->executeCriticalOperation(
            new TemplateOperation('set_theme', ['theme' => $theme]),
            new SecurityContext(['system' => true]),
            function() use ($theme) {
                $this->validator->validateTheme($theme);
                $this->themeManager->setActiveTheme($theme);
                $this->cache->invalidateTag('templates');
            }
        );
    }

    private function loadTemplate(string $template): string
    {
        $theme = $this->themeManager->getActiveTheme();
        $paths = $this->getTemplatePaths($theme, $template);

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return file_get_contents($path);
            }
        }

        throw new TemplateNotFoundException("Template not found: {$template}");
    }

    private function getTemplatePaths(string $theme, string $template): array
    {
        return [
            resource_path("themes/{$theme}/templates/{$template}.blade.php"),
            resource_path("views/{$template}.blade.php"),
            resource_path("templates/{$template}.blade.php")
        ];
    }

    private function renderCompiled(CompiledTemplate $compiled, array $data): string
    {
        try {
            $start = microtime(true);
            $result = View::make($compiled->path, $data)->render();
            $this->recordRenderMetrics($compiled, microtime(true) - $start);
            return $result;
        } catch (\Exception $e) {
            $this->handleRenderError($e, $compiled);
            throw $e;
        }
    }

    private function prepareContentData(Content $content, array $options): array
    {
        return array_merge(
            $content->toArray(),
            $options,
            [
                'theme' => $this->themeManager->getActiveTheme(),
                'meta' => $this->prepareMetaData($content),
                'assets' => $this->resolveAssets($content)
            ]
        );
    }

    private function prepareMetaData(Content $content): array
    {
        return [
            'title' => $content->meta_title ?? $content->title,
            'description' => $content->meta_description ?? substr(strip_tags($content->content), 0, 160),
            'keywords' => $content->meta_keywords ?? $this->extractKeywords($content),
            'og' => $this->prepareOpenGraph($content)
        ];
    }

    private function resolveAssets(Content $content): array
    {
        $theme = $this->themeManager->getActiveTheme();
        return [
            'css' => $this->themeManager->getStylesheets($theme),
            'js' => $this->themeManager->getScripts($theme),
            'images' => $this->resolveContentImages($content)
        ];
    }

    private function getCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template:%s:%s:%s',
            $this->themeManager->getActiveTheme(),
            $template,
            md5(serialize($data))
        );
    }

    private function getCompilerOptions(): array
    {
        return [
            'cache_path' => storage_path('framework/views'),
            'debug' => config('app.debug'),
            'safe_mode' => !config('app.debug'),
            'auto_escape' => true
        ];
    }

    private function recordRenderMetrics(CompiledTemplate $compiled, float $duration): void
    {
        $this->metrics->recordTemplateRender([
            'template' => $compiled->name,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true),
            'cache' => $compiled->cached
        ]);
    }

    private function handleRenderError(\Exception $e, CompiledTemplate $compiled): void
    {
        Log::error('Template render failed', [
            'template' => $compiled->name,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->cache->invalidate($this->getCacheKey($compiled->name, []));
    }

    private function extractKeywords(Content $content): string
    {
        // Implementation for keyword extraction
        return '';
    }

    private function prepareOpenGraph(Content $content): array
    {
        return [
            'title' => $content->meta_title ?? $content->title,
            'description' => $content->meta_description ?? substr(strip_tags($content->content), 0, 160),
            'image' => $content->featured_image ?? null,
            'type' => 'article',
            'url' => request()->url()
        ];
    }

    private function resolveContentImages(Content $content): array
    {
        // Implementation for content image resolution
        return [];
    }
}
