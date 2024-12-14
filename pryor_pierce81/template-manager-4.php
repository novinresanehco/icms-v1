<?php

namespace App\Core\Template;

use App\Core\Template\Loaders\TemplateLoader;
use App\Core\Template\Cache\TemplateCache;
use App\Core\Template\Validators\TemplateValidator;
use App\Core\Exceptions\TemplateException;

class TemplateManager
{
    protected TemplateLoader $loader;
    protected TemplateCache $cache;
    protected TemplateValidator $validator;
    protected array $templates = [];
    
    /**
     * TemplateManager constructor.
     */
    public function __construct(
        TemplateLoader $loader,
        TemplateCache $cache,
        TemplateValidator $validator
    ) {
        $this->loader = $loader;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    /**
     * Load a template
     */
    public function load(string $name): Template
    {
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        try {
            $content = $this->loader->load($name);
            
            // Validate template
            $this->validator->validate($content);
            
            $template = new Template($name, $content);
            $this->templates[$name] = $template;
            
            return $template;
        } catch (\Exception $e) {
            throw new TemplateException("Failed to load template '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Save a template
     */
    public function save(Template $template): void
    {
        try {
            // Validate template
            $this->validator->validate($template->getContent());
            
            // Save template
            $this->loader->save($template->getName(), $template->getContent());
            
            // Clear cache
            $this->cache->clear($template->getName());
            
            // Update internal registry
            $this->templates[$template->getName()] = $template;
        } catch (\Exception $e) {
            throw new TemplateException("Failed to save template: {$e->getMessage()}");
        }
    }

    /**
     * Delete a template
     */
    public function delete(string $name): void
    {
        try {
            // Delete from storage
            $this->loader->delete($name);
            
            // Clear cache
            $this->cache->clear($name);
            
            // Remove from internal registry
            unset($this->templates[$name]);
        } catch (\Exception $e) {
            throw new TemplateException("Failed to delete template '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Get all templates
     */
    public function getAllTemplates(): array
    {
        try {
            $templates = $this->loader->getAll();
            
            foreach ($templates as $name => $content) {
                if (!isset($this->templates[$name])) {
                    $this->templates[$name] = new Template($name, $content);
                }
            }
            
            return $this->templates;
        } catch (\Exception $e) {
            throw new TemplateException("Failed to get templates: {$e->getMessage()}");
        }
    }

    /**
     * Check if template exists
     */
    public function exists(string $name): bool
    {
        return isset($this->templates[$name]) || $this->loader->exists($name);
    }

    /**
     * Get template metadata
     */
    public function getMetadata(string $name): array
    {
        try {
            $template = $this->load($name);
            return [
                'name' => $template->getName(),
                'size' => strlen($template->getContent()),
                'modified' => $this->loader->getLastModified($name),
                'variables' => $this->extractVariables($template),
                'dependencies' => $this->extractDependencies($template)
            ];
        } catch (\Exception $e) {
            throw new TemplateException("Failed to get template metadata: {$e->getMessage()}");
        }
    }

    /**
     * Extract template variables
     */
    protected function extractVariables(Template $template): array
    {
        $content = $template->getContent();
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Extract template dependencies
     */
    protected function extractDependencies(Template $template): array
    {
        $content = $template->getContent();
        preg_match_all('/\{%\s*extends\s+[\'"]([^\'"]+)[\'"]\s*%\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }
}
