<?php

namespace App\Core\Template;

use App\Core\Security\CoreSecurityService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\View;

class TemplateEngine implements TemplateInterface 
{
    private CoreSecurityService $security;
    private CacheManager $cache;
    private TemplateRepository $repository;
    private TemplateCompiler $compiler;
    private SecuritySanitizer $sanitizer;

    public function __construct(
        CoreSecurityService $security,
        CacheManager $cache,
        TemplateRepository $repository,
        TemplateCompiler $compiler,
        SecuritySanitizer $sanitizer
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->compiler = $compiler;
        $this->sanitizer = $sanitizer;
    }

    public function render(string $template, array $data, Context $context): string
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeRender($template, $data),
            ['action' => 'template.render', 'template' => $template, 'context' => $context]
        );
    }

    public function compile(string $template, Context $context): CompiledTemplate
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeCompile($template),
            ['action' => 'template.compile', 'template' => $template, 'context' => $context]
        );
    }

    public function registerPartial(string $name, string $content, Context $context): void
    {
        $this->security->executeProtectedOperation(
            fn() => $this->executeRegisterPartial($name, $content),
            ['action' => 'template.register_partial', 'name' => $name, 'context' => $context]
        );
    }

    private function executeRender(string $template, array $data): string
    {
        $templateData = $this->loadTemplate($template);
        if (!$templateData) {
            throw new TemplateNotFoundException("Template not found: $template");
        }

        $cacheKey = $this->generateCacheKey($template, $data);
        return $this->cache->remember(
            $cacheKey,
            fn() => $this->renderTemplate($templateData, $data),
            config('template.cache_ttl')
        );
    }

    private function executeCompile(string $template): CompiledTemplate
    {
        $templateData = $this->loadTemplate($template);
        if (!$templateData) {
            throw new TemplateNotFoundException("Template not found: $template");
        }

        $compiled = $this->compiler->compile($templateData);
        $this->validateCompiled($compiled);
        
        return new CompiledTemplate($compiled);
    }

    private function executeRegisterPartial(string $name, string $content): void
    {
        $this->validatePartialName($name);
        $this->validatePartialContent($content);
        
        $this->repository->savePartial($name, $content);
        $this->cache->invalidatePattern("template:partial:$name:*");
    }

    private function loadTemplate(string $template): ?Template
    {
        return $this->repository->find($template);
    }

    private function renderTemplate(Template $template, array $data): string
    {
        $compiled = $this->compiler->compile($template);
        $sanitizedData = $this->sanitizeData($data);
        
        return View::make(
            'template::renderer',
            [
                'template' => $compiled,
                'data' => $sanitizedData
            ]
        )->render();
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->sanitizer->sanitize($value);
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    private function generateCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }

    private function validateCompiled(CompiledTemplate $compiled): void
    {
        if (!$this->compiler->validate($compiled)) {
            throw new TemplateCompilationException('Invalid compiled template');
        }
    }

    private function validatePartialName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-\/]+$/', $name)) {
            throw new TemplateValidationException('Invalid partial name');
        }
    }

    private function validatePartialContent(string $content): void
    {
        if (strlen($content) > config('template.max_partial_size', 65536)) {
            throw new TemplateValidationException('Partial content too large');
        }
    }
}

class TemplateCompiler
{
    private SecuritySanitizer $sanitizer;
    private array $directives;

    public function __construct(SecuritySanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
        $this->directives = $this->registerDirectives();
    }

    public function compile(Template $template): CompiledTemplate
    {
        $content = $template->content;
        
        $content = $this->compileIncludes($content);
        $content = $this->compileDirectives($content);
        $content = $this->compileEscaping($content);
        $content = $this->compileSafeStrings($content);
        
        return new CompiledTemplate($content);
    }

    public function validate(CompiledTemplate $compiled): bool
    {
        try {
            $this->validateSyntax($compiled->getContent());
            $this->validateSecurity($compiled->getContent());
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function compileIncludes(string $content): string
    {
        return preg_replace_callback(
            '/@include\([\'"]([^\'"]+)[\'"]\)/',
            fn($matches) => $this->resolveInclude($matches[1]),
            $content
        );
    }

    private function compileDirectives(string $content): string
    {
        foreach ($this->directives as $pattern => $callback) {
            $content = preg_replace_callback($pattern, $callback, $content);
        }
        return $content;
    }

    private function compileEscaping(string $content): string
    {
        return preg_replace(
            '/\{\{(.+?)\}\}/',
            '<?php echo $this->sanitizer->escape($1); ?>',
            $content
        );
    }

    private function compileSafeStrings(string $content): string
    {
        return preg_replace(
            '/\{!!(.+?)!!\}/',
            '<?php echo $this->sanitizer->sanitize($1); ?>',
            $content
        );
    }

    private function resolveInclude(string $template): string
    {
        $includedTemplate = $this->loadTemplate($template);
        if (!$includedTemplate) {
            throw new TemplateNotFoundException("Include not found: $template");
        }
        return $this->compile($includedTemplate)->getContent();
    }

    private function validateSyntax(string $content): void
    {
        if (PHP_CodeSniffer::hasError($content)) {
            throw new TemplateSyntaxException('Invalid template syntax');
        }
    }

    private function validateSecurity(string $content): void
    {
        $blacklist = ['eval', 'exec', 'system', 'shell_exec', 'passthru'];
        foreach ($blacklist as $function) {
            if (strpos($content, $function) !== false) {
                throw new TemplateSecurityException("Forbidden function: $function");
            }
        }
    }

    private function registerDirectives(): array
    {
        return [
            '/@if\((.*?)\)/' => '<?php if($1): ?>',
            '/@else/' => '<?php else: ?>',
            '/@endif/' => '<?php endif; ?>',
            '/@foreach\((.*?)\)/' => '<?php foreach($1): ?>',
            '/@endforeach/' => '<?php endforeach; ?>',
            '/@for\((.*?)\)/' => '<?php for($1): ?>',
            '/@endfor/' => '<?php endfor; ?>',
            '/@while\((.*?)\)/' => '<?php while($1): ?>',
            '/@endwhile/' => '<?php endwhile; ?>',
        ];
    }
}

class SecuritySanitizer
{
    public function sanitize(string $value): string
    {
        $value = $this->removeXSS($value);
        $value = $this->sanitizeHTML($value);
        $value = $this->escapeScript($value);
        return $value;
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function removeXSS(string $value): string
    {
        return strip_tags($value, config('template.allowed_tags'));
    }

    private function sanitizeHTML(string $value): string
    {
        return preg_replace(
            config('template.disallowed_patterns'),
            '',
            $value
        );
    }

    private function escapeScript(string $value): string
    {
        return str_replace(
            ['<script', '</script'],
            ['&lt;script', '&lt;/script'],
            $value
        );
    }
}

class TemplateRepository
{
    public function find(string $name): ?Template
    {
        return Template::where('name', $name)->first();
    }

    public function savePartial(string $name, string $content): void
    {
        Template::updateOrCreate(
            ['name' => $name],
            ['content' => $content, 'type' => 'partial']
        );
    }
}

class TemplateNotFoundException extends \Exception {}
class TemplateCompilationException extends \Exception {}
class TemplateValidationException extends \Exception {}
class TemplateSyntaxException extends \Exception {}
class TemplateSecurityException extends \Exception {}
