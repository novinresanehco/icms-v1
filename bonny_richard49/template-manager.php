<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, Storage};
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;

class TemplateManager implements TemplateInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private TemplateCompiler $compiler;
    private ValidationService $validator;
    
    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation(
                $template,
                $data,
                $this->compiler,
                $this->cache
            ),
            new SecurityContext([
                'operation' => 'template.render',
                'template' => $template
            ])
        );
    }

    public function compile(string $template): string
    {
        return $this->security->executeCriticalOperation(
            new CompileTemplateOperation($template, $this->compiler),
            new SecurityContext([
                'operation' => 'template.compile',
                'template' => $template
            ])
        );
    }

    public function validate(string $template): bool
    {
        return $this->validator->validateTemplate($template, [
            'syntax' => true,
            'security' => true,
            'performance' => true
        ]);
    }

    public function cache(string $template): void
    {
        $this->security->executeCriticalOperation(
            new CacheTemplateOperation($template, $this->cache),
            new SecurityContext([
                'operation' => 'template.cache',
                'template' => $template
            ])
        );
    }

    public function extend(string $name, callable $extension): void
    {
        $this->compiler->extend($name, $extension);
    }
}

class RenderTemplateOperation extends CriticalOperation
{
    private string $template;
    private array $data;
    private TemplateCompiler $compiler;
    private CacheManager $cache;

    public function execute(): string
    {
        $cacheKey = $this->generateCacheKey($this->template, $this->data);

        return $this->cache->remember($cacheKey, function() {
            // Compile template
            $compiled = $this->compiler->compile($this->template);
            
            // Validate compiled output
            $this->validateCompiled($compiled);
            
            // Render with data
            return View::make($compiled, $this->data)->render();
        });
    }

    private function generateCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template.%s.%s',
            md5($template),
            md5(serialize($data))
        );
    }

    private function validateCompiled(string $compiled): void
    {
        if (!$this->compiler->validate($compiled)) {
            throw new TemplateException('Invalid compiled template');
        }
    }
}

class TemplateCompiler
{
    private array $extensions = [];
    private SecurityConfig $security;

    public function compile(string $template): string
    {
        // Parse template syntax
        $ast = $this->parse($template);
        
        // Apply security rules
        $this->applySecurity($ast);
        
        // Apply extensions
        $this->applyExtensions($ast);
        
        // Generate PHP code
        return $this->generate($ast);
    }

    public function validate(string $compiled): bool
    {
        // Check syntax
        if (!$this->validateSyntax($compiled)) {
            return false;
        }

        // Check security
        if (!$this->validateSecurity($compiled)) {
            return false;
        }

        // Check extensions
        if (!$this->validateExtensions($compiled)) {
            return false;
        }

        return true;
    }

    public function extend(string $name, callable $extension): void
    {
        $this->extensions[$name] = $extension;
    }

    private function parse(string $template): array
    {
        $parser = new TemplateParser();
        return $parser->parse($template);
    }

    private function applySecurity(array &$ast): void
    {
        $visitor = new SecurityVisitor($this->security);
        $visitor->visit($ast);
    }

    private function applyExtensions(array &$ast): void
    {
        foreach ($this->extensions as $extension) {
            $extension($ast);
        }
    }

    private function generate(array $ast): string
    {
        $generator = new CodeGenerator();
        return $generator->generate($ast);
    }

    private function validateSyntax(string $compiled): bool
    {
        try {
            token_get_all($compiled, TOKEN_PARSE);
            return true;
        } catch (\ParseError $e) {
            return false;
        }
    }

    private function validateSecurity(string $compiled): bool
    {
        $forbidden = [
            'eval',
            'exec',
            'system',
            'shell_exec',
            'passthru',
            'popen',
            'proc_open',
            'include',
            'require'
        ];

        foreach ($forbidden as $function) {
            if (strpos($compiled, $function) !== false) {
                return false;
            }
        }

        return true;
    }

    private function validateExtensions(string $compiled): bool
    {
        foreach ($this->extensions as $name => $extension) {
            if (!$this->validateExtension($name, $compiled)) {
                return false;
            }
        }
        return true;
    }
}

class SecurityVisitor
{
    private SecurityConfig $security;
    
    public function visit(array &$ast): void
    {
        array_walk_recursive($ast, [$this, 'visitNode']);
    }

    private function visitNode(&$node): void
    {
        if (is_array($node)) {
            if (isset($node['type'])) {
                $this->applySecurityRules($node);
            }
        }
    }

    private function applySecurityRules(array &$node): void
    {
        switch ($node['type']) {
            case 'output':
                $this->secureOutput($node);
                break;
            case 'function':
                $this->validateFunction($node);
                break;
            case 'include':
                $this->validateInclude($node);
                break;
        }
    }

    private function secureOutput(array &$node): void
    {
        // Always escape output by default
        $node['escape'] = true;
    }

    private function validateFunction(array &$node): void
    {
        $allowedFunctions = $this->security->get('allowed_functions');
        
        if (!in_array($node['name'], $allowedFunctions)) {
            throw new SecurityException('Function not allowed: ' . $node['name']);
        }
    }

    private function validateInclude(array &$node): void
    {
        $path = $node['path'];
        
        if (!$this->isPathAllowed($path)) {
            throw new SecurityException('Include path not allowed: ' . $path);
        }
    }

    private function isPathAllowed(string $path): bool
    {
        $allowedPaths = $this->security->get('allowed_include_paths');
        
        foreach ($allowedPaths as $allowed) {
            if (strpos($path, $allowed) === 0) {
                return true;
            }
        }
        
        return false;
    }
}
