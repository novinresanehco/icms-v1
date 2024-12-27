<?php

namespace App\Core\Template;

class TemplateEngine implements TemplateEngineInterface 
{
    private SecurityValidator $security;
    private CacheManager $cache;
    private TemplateCompiler $compiler;
    private array $globals = [];

    public function __construct(
        SecurityValidator $security,
        CacheManager $cache,
        TemplateCompiler $compiler
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
    }

    public function render(string $template, array $data = []): string
    {
        $operation = new RenderOperation($template, $data, $this->compiler);
        $result = $this->security->validateOperation($operation);
        return $result->getContent();
    }

    public function cache(string $template, array $data = [], int $ttl = 3600): string
    {
        return $this->cache->remember(
            ['template', $template, md5(serialize($data))],
            fn() => $this->render($template, $data),
            $ttl
        );
    }

    public function addGlobal(string $key, $value): void
    {
        $this->globals[$key] = $value;
    }

    public function getGlobals(): array
    {
        return $this->globals;
    }
}

class TemplateCompiler implements TemplateCompilerInterface
{
    private TemplateLoader $loader;
    private array $extensions = [];
    private array $functions = [];

    public function compile(string $template): CompiledTemplate
    {
        $source = $this->loader->load($template);
        $compiled = $this->compileSource($source);
        return new CompiledTemplate($compiled);
    }

    public function addExtension(TemplateExtension $extension): void
    {
        $this->extensions[] = $extension;
        foreach ($extension->getFunctions() as $function) {
            $this->functions[$function->getName()] = $function;
        }
    }

    private function compileSource(string $source): string
    {
        $compiled = $source;
        foreach ($this->extensions as $extension) {
            $compiled = $extension->compile($compiled);
        }
        return $compiled;
    }
}

class RenderOperation implements Operation
{
    private string $template;
    private array $data;
    private TemplateCompiler $compiler;

    public function __construct(string $template, array $data, TemplateCompiler $compiler)
    {
        $this->template = $template;
        $this->data = $data;
        $this->compiler = $compiler;
    }

    public function getData(): array
    {
        return [
            'template' => $this->template,
            'data' => $this->data
        ];
    }

    public function execute(): OperationResult
    {
        $compiled = $this->compiler->compile($this->template);
        $rendered = $compiled->render($this->data);
        return new OperationResult($rendered);
    }
}

class CompiledTemplate
{
    private string $source;

    public function __construct(string $source)
    {
        $this->source = $source;
    }

    public function render(array $data): string
    {
        extract($data);
        ob_start();
        eval('?>' . $this->source);
        return ob_get_clean();
    }
}

interface TemplateEngineInterface
{
    public function render(string $template, array $data = []): string;
    public function cache(string $template, array $data = [], int $ttl = 3600): string;
    public function addGlobal(string $key, $value): void;
    public function getGlobals(): array;
}

interface TemplateCompilerInterface
{
    public function compile(string $template): CompiledTemplate;
    public function addExtension(TemplateExtension $extension): void;
}

interface TemplateExtension
{
    public function getName(): string;
    public function getFunctions(): array;
    public function compile(string $source): string;
}

interface TemplateFunction
{
    public function getName(): string;
    public function compile(string $arguments): string;
}

interface TemplateLoader
{
    public function load(string $template): string;
}