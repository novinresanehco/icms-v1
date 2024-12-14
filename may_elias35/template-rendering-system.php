<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\TemplateManagerInterface;

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private CompilerService $compiler;
    private ValidationService $validator;
    private ThemeRegistry $themes;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        CompilerService $compiler,
        ValidationService $validator,
        ThemeRegistry $themes
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->validator = $validator;
        $this->themes = $themes;
    }

    public function render(string $template, array $data, SecurityContext $context): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation(
                $template,
                $data,
                $this->compiler,
                $this->cache
            ),
            $context
        );
    }

    public function compile(string $template, SecurityContext $context): CompiledTemplate
    {
        return $this->security->executeCriticalOperation(
            new CompileTemplateOperation(
                $template,
                $this->compiler,
                $this->validator
            ),
            $context
        );
    }

    public function registerTheme(Theme $theme, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new RegisterThemeOperation($theme, $this->themes, $this->cache),
            $context
        );
    }

    public function getCachedTemplate(string $name): ?CompiledTemplate
    {
        return $this->cache->remember(
            $this->getCacheKey($name),
            config('template.cache.ttl'),
            fn() => $this->compile($name, new SecurityContext('system'))
        );
    }

    private function getCacheKey(string $name): string
    {
        return "template:{$name}";
    }
}

class RenderTemplateOperation implements CriticalOperation
{
    private string $template;
    private array $data;
    private CompilerService $compiler;
    private CacheManager $cache;

    public function __construct(
        string $template,
        array $data,
        CompilerService $compiler,
        CacheManager $cache
    ) {
        $this->template = $template;
        $this->data = $data;
        $this->compiler = $compiler;
        $this->cache = $cache;
    }

    public function execute(): string
    {
        $compiled = $this->cache->remember(
            "template:{$this->template}",
            config('template.cache.ttl'),
            fn() => $this->compiler->compile($this->template)
        );

        return $compiled->render($this->data);
    }

    public function getValidationRules(): array
    {
        return [
            'template' => 'required|string',
            'data' => 'array'
        ];
    }

    public function getData(): array
    {
        return [
            'template' => $this->template,
            'data' => $this->data
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['template:render'];
    }
}

class CompilerService
{
    private ValidationService $validator;
    private array $directives;
    private array $config;

    public function compile(string $template): CompiledTemplate
    {
        $this->validator->validateSyntax($template);
        
        $ast = $this->parse($template);
        $this->validateAst($ast);
        
        $compiled = $this->compileAst($ast);
        $this->validator->validateCompiled($compiled);
        
        return new CompiledTemplate($compiled);
    }

    private function parse(string $template): array
    {
        $parser = new TemplateParser($this->directives);
        return $parser->parse($template);
    }

    private function validateAst(array $ast): void
    {
        $validator = new AstValidator($this->config);
        $validator->validate($ast);
    }

    private function compileAst(array $ast): string
    {
        $compiler = new AstCompiler($this->directives);
        return $compiler->compile($ast);
    }

    public function registerDirective(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }
}

class CompiledTemplate
{
    private string $code;
    private array $metadata;

    public function render(array $data): string
    {
        extract($data);
        ob_start();
        
        try {
            eval('?>' . $this->code);
            return ob_get_clean();
        } catch (\Exception $e) {
            ob_end_clean();
            throw new TemplateRenderException($e->getMessage(), 0, $e);
        }
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class Theme
{
    private string $name;
    private array $templates;
    private array $assets;
    private array $config;

    public function getTemplate(string $name): ?string
    {
        return $this->templates[$name] ?? null;
    }

    public function getAsset(string $path): ?string
    {
        return $this->assets[$path] ?? null;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}

class ThemeRegistry
{
    private array $themes = [];

    public function register(Theme $theme): void
    {
        $this->themes[$theme->getName()] = $theme;
    }

    public function get(string $name): ?Theme
    {
        return $this->themes[$name] ?? null;
    }

    public function getActive(): Theme
    {
        return $this->themes[config('template.active_theme')];
    }
}
