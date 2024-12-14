<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{TemplateException, SecurityException};
use Illuminate\Support\Facades\{View, Cache};

class TemplateManager implements TemplateInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private CompilerService $compiler;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        CompilerService $compiler,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function render(string $template, array $data = [], array $options = []): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation(
                $template,
                $data,
                $options,
                $this->compiler,
                $this->validator
            ),
            SecurityContext::system()
        );
    }

    public function compile(string $template): CompiledTemplate
    {
        return $this->cache->remember(
            "template.compiled.{$this->getTemplateHash($template)}",
            fn() => $this->compiler->compile($template)
        );
    }

    public function registerExtension(string $name, callable $extension): void
    {
        if (!$this->validator->validateExtension($name, $extension)) {
            throw new TemplateException("Invalid template extension: {$name}");
        }

        $this->compiler->registerExtension($name, $extension);
    }

    public function renderContent(Content $content, array $options = []): string
    {
        return $this->security->executeCriticalOperation(
            new RenderContentOperation(
                $content,
                $options,
                $this->compiler,
                $this->validator
            ),
            SecurityContext::system()
        );
    }

    public function renderPartial(string $partial, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            new RenderPartialOperation(
                $partial,
                $data,
                $this->compiler,
                $this->validator
            ),
            SecurityContext::system()
        );
    }

    private function getTemplateHash(string $template): string
    {
        return hash('sha256', $template);
    }
}

class CompilerService
{
    private array $extensions = [];
    private ValidationService $validator;
    private array $config;

    public function compile(string $template): CompiledTemplate
    {
        $this->validateTemplate($template);
        
        $ast = $this->parse($template);
        $this->validateAst($ast);
        
        $compiled = $this->compileAst($ast);
        $this->optimizeCompiled($compiled);
        
        return new CompiledTemplate($compiled);
    }

    public function registerExtension(string $name, callable $extension): void
    {
        $this->extensions[$name] = $extension;
    }

    private function parse(string $template): array
    {
        $tokens = $this->tokenize($template);
        return $this->buildAst($tokens);
    }

    private function tokenize(string $template): array
    {
        $pattern = '/{%\s*([^}]+)\s*%}|{{\s*([^}]+)\s*}}|{#\s*([^}]+)\s*#}/';
        return preg_split($pattern, $template, -1, PREG_SPLIT_DELIM_CAPTURE);
    }

    private function buildAst(array $tokens): array
    {
        $ast = [];
        $stack = [&$ast];
        
        foreach ($tokens as $token) {
            if ($this->isControlToken($token)) {
                $this->handleControlToken($token, $stack);
            } else {
                $current = &$stack[count($stack) - 1];
                $current[] = $this->createNode($token);
            }
        }
        
        return $ast;
    }

    private function compileAst(array $ast): string
    {
        $output = '';
        
        foreach ($ast as $node) {
            $output .= $this->compileNode($node);
        }
        
        return $output;
    }

    private function compileNode(array $node): string
    {
        return match ($node['type']) {
            'text' => $this->escapeHtml($node['content']),
            'variable' => $this->compileVariable($node),
            'block' => $this->compileBlock($node),
            'extension' => $this->compileExtension($node),
            default => throw new TemplateException("Unknown node type: {$node['type']}")
        };
    }

    private function validateTemplate(string $template): void
    {
        if (strlen($template) > $this->config['max_template_size']) {
            throw new TemplateException('Template too large');
        }

        if (!$this->validator->validateSyntax($template)) {
            throw new TemplateException('Invalid template syntax');
        }

        $this->validateSecurityConstraints($template);
    }

    private function validateAst(array $ast): void
    {
        $this->validateNesting($ast);
        $this->validateExtensions($ast);
        $this->validateVariables($ast);
    }

    private function validateSecurityConstraints(string $template): void
    {
        if (preg_match($this->config['dangerous_pattern'], $template)) {
            throw new SecurityException('Template contains dangerous patterns');
        }

        foreach ($this->config['forbidden_functions'] as $function) {
            if (stripos($template, $function) !== false) {
                throw new SecurityException("Forbidden function in template: {$function}");
            }
        }
    }

    private function optimizeCompiled(string &$compiled): void
    {
        $compiled = preg_replace('/\s+/', ' ', $compiled);
        $compiled = str_replace(['> <', '" "'], ['><', '""'], $compiled);
    }

    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function isControlToken(string $token): bool
    {
        return strpos($token, '{%') === 0;
    }

    private function handleControlToken(string $token, array &$stack): void
    {
        if (preg_match('/^{%\s*end(\w+)\s*%}$/', $token, $matches)) {
            array_pop($stack);
        } else {
            $node = $this->createBlockNode($token);
            $current = &$stack[count($stack) - 1];
            $current[] = $node;
            $stack[] = &$node['children'];
        }
    }

    private function createNode(string $content): array
    {
        if (preg_match('/^{{\s*(.+)\s*}}$/', $content, $matches)) {
            return [
                'type' => 'variable',
                'name' => trim($matches[1])
            ];
        }
        
        return [
            'type' => 'text',
            'content' => $content
        ];
    }

    private function createBlockNode(string $token): array
    {
        preg_match('/^{%\s*(\w+)(.*?)%}$/', $token, $matches);
        
        return [
            'type' => 'block',
            'name' => $matches[1],
            'args' => trim($matches[2]),
            'children' => []
        ];
    }
}
