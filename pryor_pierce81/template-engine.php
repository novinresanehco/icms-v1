<?php

namespace App\Core\Template;

use App\Core\Template\Parsers\TemplateParser;
use App\Core\Template\Compilers\TemplateCompiler;
use App\Core\Template\Renderers\TemplateRenderer;
use App\Core\Exceptions\TemplateException;

class TemplateEngine
{
    protected TemplateParser $parser;
    protected TemplateCompiler $compiler;
    protected TemplateRenderer $renderer;
    protected array $extensions = [];
    
    /**
     * TemplateEngine constructor.
     */
    public function __construct(
        TemplateParser $parser,
        TemplateCompiler $compiler,
        TemplateRenderer $renderer
    ) {
        $this->parser = $parser;
        $this->compiler = $compiler;
        $this->renderer = $renderer;
    }

    /**
     * Compile a template
     */
    public function compile(string $template, array $options = []): string
    {
        try {
            // Parse template
            $ast = $this->parser->parse($template);

            // Apply transformations
            $ast = $this->applyTransformations($ast);

            // Compile to PHP
            return $this->compiler->compile($ast, $options);
        } catch (\Exception $e) {
            throw new TemplateException("Template compilation failed: {$e->getMessage()}");
        }
    }

    /**
     * Render a compiled template
     */
    public function render(string $compiled, array $data = []): string
    {
        try {
            return $this->renderer->render($compiled, $data);
        } catch (\Exception $e) {
            throw new TemplateException("Template rendering failed: {$e->getMessage()}");
        }
    }

    /**
     * Register a template extension
     */
    public function registerExtension(TemplateExtension $extension): void
    {
        $this->extensions[$extension->getName()] = $extension;
    }

    /**
     * Apply AST transformations
     */
    protected function applyTransformations(array $ast): array
    {
        foreach ($this->extensions as $extension) {
            $ast = $extension->transform($ast);
        }
        return $ast;
    }

    /**
     * Analyze template layout
     */
    public function analyzeLayout(string $layout): array
    {
        try {
            $ast = $this->parser->parse($layout);
            return $this->extractLayoutVariables($ast);
        } catch (\Exception $e) {
            throw new TemplateException("Layout analysis failed: {$e->getMessage()}");
        }
    }

    /**
     * Extract variables from layout AST
     */
    protected function extractLayoutVariables(array $ast): array
    {
        $variables = [];
        
        foreach ($ast['nodes'] as $node) {
            if ($node['type'] === 'variable') {
                $variables[] = $node['name'];
            }
            if (!empty($node['nodes'])) {
                $variables = array_merge(
                    $variables,
                    $this->extractLayoutVariables(['nodes' => $node['nodes']])
                );
            }
        }
        
        return array_unique($variables);
    }
}
