<?php

namespace App\Core\Template;

use App\Core\Interfaces\TemplateInterface;
use App\Core\Services\{CacheManager, SecurityManager};
use App\Core\Exceptions\TemplateException;

class TemplateManager implements TemplateInterface
{
    private CacheManager $cache;
    private SecurityManager $security;
    private array $config;
    private array $compiled = [];
    private array $extensions = [];

    public function __construct(
        CacheManager $cache,
        SecurityManager $security,
        array $config
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->config = $config;
    }

    public function render(string $template, array $data = [], array $options = []): string
    {
        try {
            $this->security->validateOperation('template.render', [
                'template' => $template,
                'options' => $options
            ]);

            $compiled = $this->compile($template);
            $sanitizedData = $this->sanitizeData($data);
            
            return $this->cache->remember(
                $this->getCacheKey($template, $sanitizedData),
                fn() => $this->renderCompiled($compiled, $sanitizedData)
            );
        } catch (\Exception $e) {
            throw new TemplateException('Template rendering failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function compile(string $template): string
    {
        try {
            if (isset($this->compiled[$template])) {
                return $this->compiled[$template];
            }

            $path = $this->resolvePath($template);
            $content = $this->loadTemplate($path);
            
            $compiled = $this->processIncludes($content);
            $compiled = $this->processExtensions($compiled);
            $compiled = $this->compileDirectives($compiled);
            $compiled = $this->compileEscaping($compiled);
            
            $this->compiled[$template] = $compiled;
            return $compiled;
        } catch (\Exception $e) {
            throw new TemplateException('Template compilation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function extend(string $name, callable $handler): void
    {
        $this->extensions[$name] = $handler;
    }

    public function clearCache(string $template = null): void
    {
        if ($template) {
            $this->cache->tags(['template', "template:{$template}"])->flush();
            unset($this->compiled[$template]);
        } else {
            $this->cache->tags(['template'])->flush();
            $this->compiled = [];
        }
    }

    protected function resolvePath(string $template): string
    {
        $path = $this->config['template_path'] . '/' . $template;
        
        if (!file_exists($path)) {
            throw new TemplateException("Template not found: {$template}");
        }
        
        return $path;
    }

    protected function loadTemplate(string $path): string
    {
        $content = file_get_contents($path);
        
        if ($content === false) {
            throw new TemplateException("Failed to load template: {$path}");
        }
        
        return $content;
    }

    protected function processIncludes(string $content): string
    {
        return preg_replace_callback(
            '/@include\([\'"](.*?)[\'"]\)/',
            fn($matches) => $this->loadTemplate($this->resolvePath($matches[1])),
            $content
        );
    }

    protected function processExtensions(string $content): string
    {
        foreach ($this->extensions as $name => $handler) {
            $content = preg_replace_callback(
                "/@{$name}\((.*?)\)/",
                fn($matches) => $handler($matches[1]),
                $content
            );
        }
        
        return $content;
    }

    protected function compileDirectives(string $content): string
    {
        $directives = [
            '/@if\((.*?)\)/' => '<?php if($1): ?>',
            '/@else/' => '<?php else: ?>',
            '/@endif/' => '<?php endif; ?>',
            '/@foreach\((.*?)\)/' => '<?php foreach($1): ?>',
            '/@endforeach/' => '<?php endforeach; ?>',
            '/@for\((.*?)\)/' => '<?php for($1): ?>',
            '/@endfor/' => '<?php endfor; ?>',
            '/@while\((.*?)\)/' => '<?php while($1): ?>',
            '/@endwhile/' => '<?php endwhile; ?>',
            '/\{\{(.*?)\}\}/' => '<?php echo $this->escape($1); ?>',
            '/\{\!\!(.*?)\!\!\}/' => '<?php echo $1; ?>'
        ];

        return preg_replace(
            array_keys($directives),
            array_values($directives),
            $content
        );
    }

    protected function compileEscaping(string $content): string
    {
        return preg_replace(
            '/\{\{\{(.*?)\}\}\}/',
            '<?php echo htmlspecialchars($1, ENT_QUOTES, \'UTF-8\', true); ?>',
            $content
        );
    }

    protected function renderCompiled(string $compiled, array $data): string
    {
        extract($data);
        ob_start();
        
        try {
            eval('?>' . $compiled);
            return ob_get_clean();
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            
            if (is_string($value)) {
                return $this->security->validateInput($value);
            }
            
            return $value;
        }, $data);
    }

    protected function escape($value): string
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', true);
        }
        
        if (is_numeric($value)) {
            return (string)$value;
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_null($value)) {
            return '';
        }
        
        throw new TemplateException('Cannot escape value of type: ' . gettype($value));
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return "template:{$template}:" . md5(json_encode($data));
    }

    public function __destruct()
    {
        $this->compiled = [];
    }
}
