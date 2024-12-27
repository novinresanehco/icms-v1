<?php

namespace App\Core\Template\Compilation;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Template\Exceptions\CompilationException;

class TemplateCompiler
{
    private SecurityManagerInterface $security;
    private array $compilers = [];
    private string $compilePath;

    public function __construct(
        SecurityManagerInterface $security,
        string $compilePath
    ) {
        $this->security = $security;
        $this->compilePath = $compilePath;
    }

    public function compile(string $template): CompiledTemplate
    {
        return $this->security->executeInContext(function() use ($template) {
            $content = $this->getTemplateContent($template);
            $compiled = $this->compileString($content);
            
            $path = $this->getCompiledPath($template);
            file_put_contents($path, $compiled);

            return new CompiledTemplate($path);
        });
    }

    public function addCompiler(Compiler $compiler): void
    {
        $this->compilers[] = $compiler;
    }

    private function compileString(string $content): string
    {
        foreach ($this->compilers as $compiler) {
            $content = $compiler->compile($content);
        }

        return $content;
    }

    private function getTemplateContent(string $template): string
    {
        if (!$this->security->validateFile($template)) {
            throw new CompilationException(
                "Invalid template file: {$template}",
                $template
            );
        }

        if (!file_exists($template)) {
            throw new CompilationException(
                "Template not found: {$template}",
                $template
            );
        }

        return file_get_contents($template);
    }

    private function getCompiledPath(string $template): string
    {
        return $this->compilePath . '/' . hash('sha256', $template) . '.php';
    }
}

interface Compiler
{
    public function compile(string $content): string;
}

class EchoCompiler implements Compiler
{
    public function compile(string $content): string
    {
        $pattern = '/\{\{\s*(.+?)\s*\}\}/';
        
        return preg_replace_callback($pattern, function($matches) {
            $expression = $this->compileExpression($matches[1]);
            return "<?php echo e({$expression}); ?>";
        }, $content);
    }

    private function compileExpression(string $expression): string
    {
        return trim($expression);
    }
}

class PhpCompiler implements Compiler
{
    private const ALLOWED_STATEMENTS = [
        'if',
        'else',
        'elseif',
        'foreach',
        'for',
        'while',
        'switch',
        'case',
        'break',
        'continue',
        'endforeach',
        'endfor',
        'endwhile',
        'endif',
        'endswitch'
    ];

    public function compile(string $content): string
    {
        $pattern = '/@(\\w+)(\\s*\\(.*?\\))?/';
        
        return preg_replace_callback($pattern, function($matches) {
            $statement = $matches[1];
            $expression = $matches[2] ?? '';
            
            if (!in_array($statement, self::ALLOWED_STATEMENTS)) {
                throw new CompilationException(
                    "Unsafe PHP statement: {$statement}"
                );
            }
            
            return "<?php {$statement}{$expression}: ?>";
        }, $content);
    }
}

class DirectiveCompiler implements Compiler
{
    private array $directives = [];

    public function addDirective(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }

    public function compile(string $content): string
    {
        foreach ($this->directives as $name => $handler) {
            $pattern = "/@{$name}(\\s*\\(.*?\\))?/";
            
            $content = preg_replace_callback($pattern, function($matches) use ($handler) {
                $arguments = $this->parseArguments($matches[1] ?? '');
                return $handler(...$arguments);
            }, $content);
        }

        return $content;
    }

    private function parseArguments(string $expression): array
    {
        if (empty($expression)) {
            return [];
        }

        $expression = trim($expression, '() ');
        return array_map('trim', explode(',', $expression));
    }
}

class SecurityCompiler implements Compiler
{
    private SecurityManagerInterface $security;

    public function __construct(SecurityManagerInterface $security)
    {
        $this->security = $security;
    }

    public function compile(string $content): string
    {
        // Remove PHP tags
        $content = preg_replace('/<\?php|\?>/', '', $content);

        // Escape potentially harmful content
        $content = $this->escapeHarmfulContent($content);

        // Add security headers
        return $this->addSecurityHeaders($content);
    }

    private function escapeHarmfulContent(string $content): string
    {
        $patterns = [
            '/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|ENV|FILES)/',
            '/eval\s*\(/',
            '/system\s*\(/',
            '/exec\s*\(/',
            '/shell_exec\s*\(/',
            '/passthru\s*\(/',
            '/`.*`/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new CompilationException('Potentially harmful content detected');
            }
        }

        return $content;
    }

    private function addSecurityHeaders(string $content): string
    {
        return "<?php defined('SAFE_MODE') or die(); ?>\n" . $content;
    }
}
