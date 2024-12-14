<?php

namespace App\Core\Template;

class TemplateRenderer implements RendererInterface
{
    private TemplateLoader $loader;
    private SecurityManager $security;
    private PerformanceMonitor $monitor;

    public function __construct(
        TemplateLoader $loader,
        SecurityManager $security,
        PerformanceMonitor $monitor
    ) {
        $this->loader = $loader;
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function render(string $template, array $data = []): string
    {
        $span = $this->monitor->startSpan('template_render');
        
        try {
            // Load and validate template
            $template = $this->loader->load($template);
            $this->security->validateTemplateContents($template);
            
            // Process data
            $data = $this->prepareData($data);
            
            // Render with monitoring
            return $this->renderWithMonitoring($template, $data);
            
        } finally {
            $span->end();
        }
    }

    private function prepareData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->security->escapeHtml($value);
            }
            return $value;
        }, $data);
    }

    private function renderWithMonitoring(string $template, array $data): string
    {
        $monitor = $this->monitor->startOperation('template_processing');
        
        try {
            extract($data, EXTR_SKIP);
            ob_start();
            eval('?>' . $template);
            return ob_get_clean();
            
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new RenderException($e->getMessage());
            
        } finally {
            $monitor->end();
        }
    }
}
