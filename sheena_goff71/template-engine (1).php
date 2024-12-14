<?php

namespace App\Core\Template\Engine;

class TemplateEngine implements EngineInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private CompilerInterface $compiler;
    private ValidatorInterface $validator;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        CompilerInterface $compiler,
        ValidatorInterface $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string
    {
        return DB::transaction(function() use ($template, $data) {
            $this->security->validateTemplate($template);
            $this->validator->validateData($data);

            $cacheKey = $this->getCacheKey($template, $data);
            
            return $this->cache->remember($cacheKey, function() use ($template, $data) {
                $compiled = $this->compiler->compile($template);
                return $this->renderCompiled($compiled, $data);
            });
        });
    }

    public function registerDirective(string $name, callable $handler): void
    {
        $this->validator->validateDirective($name);
        $this->compiler->addDirective($name, $handler);
    }

    public function extend(string $name, callable $extension): void
    {
        $this->validator->validateExtension($name);
        $this->compiler->addExtension($name, $extension);
    }

    private function renderCompiled(CompiledTemplate $compiled, array $data): string
    {
        $context = new RenderContext($data);
        return $compiled->render($context);
    }

    private function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }
}

class TemplateCompiler implements CompilerInterface
{
    private array $directives = [];
    private array $extensions = [];

    public function compile(string $template): CompiledTemplate
    {
        $ast = $this->parse($template);
        $optimized = $this->optimize($ast);
        return new CompiledTemplate($optimized);
    }

    public function addDirective(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }

    public function addExtension(string $name, callable $extension): void
    {
        $this->extensions[$name] = $extension;
    }

    private function parse(string $template): SyntaxTree
    {
        $parser = new TemplateParser($this->directives);
        return $parser->parse($template);
    }

    private function optimize(SyntaxTree $ast): OptimizedTree
    {
        $optimizer = new TemplateOptimizer();
        return $optimizer->optimize($ast);
    }
}

class RenderContext
{
    private array $data;
    private array $stack = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function push(array $data): void
    {
        $this->stack[] = $this->data;
        $this->data = array_merge($this->data, $data);
    }

    public function pop(): void
    {
        $this->data = array_pop($this->stack) ?? [];
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }
}

class CompiledTemplate
{
    private OptimizedTree $tree;

    public function __construct(OptimizedTree $tree)
    {
        $this->tree = $tree;
    }

    public function render(RenderContext $context): string
    {
        return $this->tree->render($context);
    }
}

interface EngineInterface
{
    public function render(string $template, array $data = []): string;
    public function registerDirective(string $name, callable $handler): void;
    public function extend(string $name, callable $extension): void;
}

interface CompilerInterface
{
    public function compile(string $template): CompiledTemplate;
    public function addDirective(string $name, callable $handler): void;
    public function addExtension(string $name, callable $extension): void;
}

interface ValidatorInterface
{
    public function validateData(array $data): void;
    public function validateDirective(string $name): void;
    public function validateExtension(string $name): void;
}
