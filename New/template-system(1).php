<?php

namespace App\Core\Template;

use App\Core\Template\Interfaces\{
    TemplateEngineInterface,
    ThemeManagerInterface,
    BlockManagerInterface
};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;

/**
 * Core template engine implementation with security and caching
 */
class TemplateEngine implements TemplateEngineInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private CompilerService $compiler;
    private BlockManagerInterface $blocks;
    private string $compilePath;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        CompilerService $compiler,
        BlockManagerInterface $blocks,
        string $compilePath
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->blocks = $blocks;
        $this->compilePath = $compilePath;
    }

    /**
     * Renders a template with provided data
     * 
     * @param string $template Template name
     * @param array $data Template variables
     * @throws TemplateException If template is invalid or rendering fails
     */
    public function render(string $template, array $data = []): string
    {
        // Validate template and data
        $this->validateTemplate($template);
        $this->validateData($data);
        
        $cacheKey = $this->getCacheKey($template, $data);
        
        return $this->cache->remember($cacheKey, function() use ($template, $data) {
            // Compile template
            $compiled = $this->compiler->compile($template);
            
            // Validate compiled template
            $this->validateCompiled($compiled);
            
            // Render with security context
            return $this->renderSecure($compiled, $data);
        });
    }

    /**
     * Validates template name and permissions
     */
    private function validateTemplate(string $template): void
    {
        if (!$this->security->validateResource($template)) {
            throw new TemplateException("Invalid template access: {$template}");
        }
    }

    /**
     * Validates template data
     */ 
    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->security->validateData($key, $value)) {
                throw new TemplateException("Invalid template data: {$key}");
            }
        }
    }

    /**
     * Validates compiled template
     */
    private function validateCompiled(CompiledTemplate $compiled): void
    {
        if (!$this->security->validateFile($compiled->getPath())) {
            throw new TemplateException('Invalid compiled template');
        }
    }

    /**
     * Renders template in secure context
     */
    private function renderSecure(CompiledTemplate $compiled, array $data): string
    {
        return $this->security->executeInContext(function() use ($compiled, $data) {
            // Extract data in clean scope
            extract($data);
            
            // Include compiled template
            ob_start();
            include $compiled->getPath();
            return ob_get_clean();
        });
    }

    /**
     * Generates secure cache key for template
     */
    private function getCacheKey(string $template, array $data): string
    {
        return hash('sha256', $template . serialize($data));
    }
}

/**
 * Template compiler service
 */
class CompilerService
{
    private string $compilePath;
    private array $compilers;

    /**
     * Compiles template to PHP code
     */
    public function compile(string $template): CompiledTemplate
    {
        $code = file_get_contents($template);
        
        // Apply compilers in sequence
        foreach ($this->compilers as $compiler) {
            $code = $compiler->compile($code);
        }

        // Generate compiled file path
        $path = $this->getCompiledPath($template);
        
        // Write compiled code
        file_put_contents($path, $code);

        return new CompiledTemplate($path);
    }

    private function getCompiledPath(string $template): string
    {
        return $this->compilePath . '/' . hash('sha256', $template) . '.php';
    }
}

/**
 * Block manager for template parts
 */
class BlockManager implements BlockManagerInterface
{
    private array $blocks = [];
    private SecurityManagerInterface $security;

    public function register(string $name, callable $renderer): void
    {
        $this->blocks[$name] = $renderer;
    }

    public function render(string $name, array $data = []): string
    {
        if (!isset($this->blocks[$name])) {
            throw new TemplateException("Unknown block: {$name}");
        }

        return $this->security->executeInContext(function() use ($name, $data) {
            return call_user_func($this->blocks[$name], $data); 
        });
    }
}
