<?php

namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private CacheManager $cache;
    private TemplateCompiler $compiler;
    private ValidationService $validator;

    public function render(string $template, array $data): TemplateResult
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation(
                $template,
                $data,
                $this->repository,
                $this->compiler,
                $this->cache
            )
        );
    }

    public function registerTemplate(string $name, string $content): bool
    {
        return $this->security->executeCriticalOperation(
            new RegisterTemplateOperation(
                $name,
                $content,
                $this->repository,
                $this->validator,
                $this->compiler
            )
        );
    }

    public function getTemplate(string $name): ?Template
    {
        return $this->cache->remember(
            "template.$name",
            fn() => $this->repository->findByName($name)
        );
    }
}

class TemplateCompiler implements CompilerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;

    public function compile(string $template): CompiledTemplate
    {
        $this->validateTemplate($template);
        
        return new CompiledTemplate(
            $this->compileTemplate($template),
            $this->extractDependencies($template)
        );
    }

    private function compileTemplate(string $template): string
    {
        $compiled = $this->security->executeCriticalOperation(
            new CompileTemplateOperation($template, $this->validator)
        );

        return $compiled->getContent();
    }

    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateSyntax($template)) {
            throw new TemplateSyntaxException('Invalid template syntax');
        }

        if ($this->containsMaliciousCode($template)) {
            throw new SecurityException('Potential security threat detected');
        }
    }

    private function extractDependencies(string $template): array
    {
        preg_match_all(
            '/@include\([\'"](.*?)[\'"]\)/',
            $template,
            $matches
        );

        return array_unique($matches[1] ?? []);
    }

    private function containsMaliciousCode(string $template): bool
    {
        $patterns = [
            '/\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV|SESSION)/',
            '/(?:exec|system|passthru|eval|shell_exec)/',
            '/<\?php/',
            '/\bfile_\w+/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $template)) {
                return true;
            }
        }

        return false;
    }
}

class RenderTemplateOperation implements CriticalOperation
{
    private string $template;
    private array $data;
    private TemplateRepository $repository;
    private TemplateCompiler $compiler;
    private CacheManager $cache;

    public function execute(): TemplateResult
    {
        $compiled = $this->cache->remember(
            "compiled_template.{$this->template}",
            fn() => $this->compiler->compile(
                $this->repository->findByName($this->template)->content
            )
        );

        $rendered = $this->renderTemplate($compiled, $this->data);

        return new TemplateResult($rendered);
    }

    private function renderTemplate(CompiledTemplate $compiled, array $data): string
    {
        $renderer = new SecureTemplateRenderer();
        return $renderer->render($compiled, $data);
    }

    public function getRequiredPermissions(): array
    {
        return ['template.render'];
    }
}

class SecureTemplateRenderer
{
    private ValidationService $validator;
    private SecurityContext $context;

    public function render(CompiledTemplate $template, array $data): string
    {
        $this->validator->validateData($data);
        
        $sandbox = new TemplateSandbox($this->context);
        return $sandbox->execute(function() use ($template, $data) {
            extract($this->sanitizeData($data));
            ob_start();
            eval('?>' . $template->getContent());
            return ob_get_clean();
        });
    }

    private function sanitizeData(array $data): array
    {
        return array_map(
            fn($value) => is_string($value) ? htmlspecialchars($value) : $value,
            $data
        );
    }
}

class TemplateSandbox
{
    private SecurityContext $context;
    private array $allowedFunctions = [
        'count', 'empty', 'isset', 'strlen',
        'strtolower', 'strtoupper', 'trim'
    ];

    public function execute(callable $code): string
    {
        $this->setupSandbox();
        
        try {
            return $code();
        } finally {
            $this->teardownSandbox();
        }
    }

    private function setupSandbox(): void
    {
        $this->context->restrictFunctions(
            array_diff(
                get_defined_functions()['internal'],
                $this->allowedFunctions
            )
        );
    }

    private function teardownSandbox(): void
    {
        $this->context->restoreEnvironment();
    }
}
