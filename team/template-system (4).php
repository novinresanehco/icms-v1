<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{View, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Performance\PerformanceManager;
use App\Core\Template\Engines\{TemplateEngine, ViewEngine};
use App\Core\Template\DTOs\{RenderContext, TemplateData};
use App\Core\Exceptions\{TemplateException, RenderException};

class TemplateManager implements TemplateInterface
{
    private SecurityManager $security;
    private PerformanceManager $performance;
    private TemplateEngine $engine;
    private ViewEngine $viewEngine;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function render(string $template, array $data, RenderContext $context): string
    {
        return $this->security->executeCriticalOperation(
            new RenderOperation($template, $data),
            $context->toSecurityContext(),
            function() use ($template, $data, $context) {
                return $this->performance->withCaching(
                    "template:{$template}:" . md5(serialize($data)),
                    fn() => $this->renderTemplate($template, $data, $context),
                    ['templates', "template:{$template}"],
                    3600
                );
            }
        );
    }

    protected function renderTemplate(string $template, array $data, RenderContext $context): string 
    {
        try {
            $validated = $this->validator->validateTemplateData($data);
            
            if ($context->isSecure()) {
                $validated = $this->sanitizeData($validated);
            }

            $compiled = $this->engine->compile($template);
            
            $rendered = $this->viewEngine->render($compiled, $validated);
            
            if ($context->needsOptimization()) {
                $rendered = $this->optimizeOutput($rendered);
            }

            $this->auditLogger->logTemplateRender($template, $context);
            
            return $rendered;
            
        } catch (\Exception $e) {
            $this->auditLogger->logRenderFailure($template, $e);
            throw new RenderException('Template rendering failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function compile(string $template, array $options = []): TemplateData 
    {
        return $this->security->executeCriticalOperation(
            new CompileOperation($template),
            new SecurityContext(['type' => 'template_compilation']),
            function() use ($template, $options) {
                try {
                    $compiled = $this->engine->compile($template);
                    
                    $this->validator->validateCompiledTemplate($compiled);
                    
                    if ($options['cache'] ?? true) {
                        $this->cacheCompiledTemplate($template, $compiled);
                    }

                    return new TemplateData($compiled);
                    
                } catch (\Exception $e) {
                    $this->auditLogger->logCompilationFailure($template, $e);
                    throw new TemplateException('Template compilation failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    protected function optimizeOutput(string $output): string
    {
        if ($this->config->isMinificationEnabled()) {
            $output = $this->minifyHtml($output);
        }

        if ($this->config->isCompressionEnabled()) {
            $output = $this->compressOutput($output);
        }

        return $output;
    }

    protected function minifyHtml(string $html): string
    {
        $search = [
            '/\>[^\S ]+/s',     // strip whitespaces after tags
            '/[^\S ]+\</s',     // strip whitespaces before tags
            '/(\s)+/s',         // shorten multiple whitespace sequences
            '/<!--(.|\s)*?-->/' // Remove HTML comments
        ];

        $replace = [
            '>',
            '<',
            '\\1',
            ''
        ];

        return preg_replace($search, $replace, $html);
    }

    protected function compressOutput(string $output): string
    {
        if (extension_loaded('zlib')) {
            return gzencode($output, 9);
        }
        return $output;
    }

    protected function cacheCompiledTemplate(string $template, string $compiled): void
    {
        $key = "compiled_template:" . md5($template);
        Cache::tags(['templates', 'compiled'])->put($key, $compiled, now()->addDay());
    }

    public function clearCache(array $tags = []): bool
    {
        try {
            if (empty($tags)) {
                return Cache::tags(['templates'])->flush();
            }
            return Cache::tags($tags)->flush();
        } catch (\Exception $e) {
            $this->auditLogger->logCacheClearFailure($e);
            throw new TemplateException('Template cache clear failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function extend(string $name, callable $extension): void
    {
        $this->security->executeCriticalOperation(
            new ExtensionOperation($name),
            new SecurityContext(['type' => 'template_extension']),
            function() use ($name, $extension) {
                try {
                    $this->engine->registerExtension($name, $extension);
                    $this->clearCache();
                } catch (\Exception $e) {
                    $this->auditLogger->logExtensionFailure($name, $e);
                    throw new TemplateException('Template extension failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }
}
