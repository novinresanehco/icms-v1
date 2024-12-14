<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\Cache;
use Illuminate\View\Factory;
use App\Core\Security\SecurityManager;
use App\Core\Template\Contracts\TemplateEngineInterface;

class TemplateEngine implements TemplateEngineInterface
{
    private Factory $viewFactory;
    private SecurityManager $security;
    private array $compiledTemplates = [];
    private const CACHE_TTL = 3600;

    public function __construct(Factory $viewFactory, SecurityManager $security)
    {
        $this->viewFactory = $viewFactory;
        $this->security = $security;
    }

    public function render(string $template, array $data = [], array $sections = []): string
    {
        return $this->security->executeSecure(function() use ($template, $data, $sections) {
            $compiledTemplate = $this->compileTemplate($template);
            
            // Inject sections if provided
            foreach ($sections as $name => $content) {
                $this->viewFactory->startSection($name);
                echo $content;
                $this->viewFactory->stopSection();
            }

            return $this->viewFactory->make($compiledTemplate, $data)->render();
        });
    }

    public function compileTemplate(string $template): string
    {
        return Cache::remember(
            "template:{$template}",
            self::CACHE_TTL,
            fn() => $this->doCompileTemplate($template)
        );
    }

    protected function doCompileTemplate(string $template): string
    {
        // Security: Validate template path
        if (!$this->isValidTemplatePath($template)) {
            throw new TemplateSecurityException("Invalid template path: {$template}");
        }

        $templatePath = $this->resolveTemplatePath($template);
        
        if (!isset($this->compiledTemplates[$templatePath])) {
            $this->compiledTemplates[$templatePath] = $this->compileTemplateFile($templatePath);
        }

        return $this->compiledTemplates[$templatePath];
    }

    protected function compileTemplateFile(string $path): string
    {
        $content = file_get_contents($path);
        
        // Security: Scan for potentially malicious code
        $this->securityScanTemplate($content);
        
        return $this->compileSyntax($content);
    }

    protected function compileSyntax(string $content): string
    {
        // Convert template syntax to PHP
        $content = preg_replace('/\{\{(.+?)\}\}/', '<?php echo $1; ?>', $content);
        $content = preg_replace('/\{%(.+?)%\}/', '<?php $1; ?>', $content);
        
        return $content;
    }

    protected function isValidTemplatePath(string $template): bool
    {
        // Security: Prevent directory traversal
        return !preg_match('/\.\.\/|\.\.\\\/', $template);
    }

    protected function resolveTemplatePath(string $template): string
    {
        return resource_path('views/' . str_replace('.', '/', $template) . '.blade.php');
    }

    protected function securityScanTemplate(string $content): void
    {
        $dangerousPatterns = [
            '/eval\s*\(/',
            '/system\s*\(/',
            '/exec\s*\(/',
            '/shell_exec\s*\(/'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new TemplateSecurityException('Potentially malicious code detected in template');
            }
        }
    }
}
