<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{DB, Cache, View};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $auditLogger;
    private TemplateCompiler $compiler;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $auditLogger,
        TemplateCompiler $compiler
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
        $this->compiler = $compiler;
    }

    /**
     * Render template with security checks and caching
     */
    public function renderTemplate(string $template, array $data, array $context): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTemplateRender($template, $data),
            $context
        );
    }

    /**
     * Register new template with security validation
     */
    public function registerTemplate(array $templateData, array $context): Template
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTemplateRegistration($templateData),
            $context
        );
    }

    /**
     * Update template with version control
     */
    public function updateTemplate(int $id, array $data, array $context): Template
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTemplateUpdate($id, $data),
            $context
        );
    }

    /**
     * Compile template with security validation
     */
    public function compileTemplate(string $template, array $context): CompiledTemplate
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTemplateCompilation($template),
            $context
        );
    }

    protected function processTemplateRender(string $template, array $data): string
    {
        $cacheKey = $this->getTemplateCacheKey($template, $data);

        return $this->cache->remember($cacheKey, 3600, function() use ($template, $data) {
            // Validate template data
            $this->validator->validateTemplateData($data);

            // Sanitize and prepare data
            $safeData = $this->sanitizeTemplateData($data);

            // Compile and render template
            $compiled = $this->compiler->compile($template);
            return View::make($compiled, $safeData)->render();
        });
    }

    protected function processTemplateRegistration(array $templateData): Template
    {
        // Validate template structure
        $validatedData = $this->validator->validate($templateData, [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|in:page,partial,layout',
            'variables' => 'array'
        ]);

        DB::beginTransaction();
        try {
            // Verify template security
            $this->validateTemplateSecurity($validatedData['content']);

            // Create template record
            $template = Template::create($validatedData);

            // Process template components
            $this->processTemplateComponents($template, $validatedData);

            // Compile template
            $this->compiler->compileAndStore($template);

            DB::commit();
            $this->cache->tags(['templates'])->flush();

            return $template;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateException('Template registration failed: ' . $e->getMessage());
        }
    }

    protected function processTemplateUpdate(int $id, array $data): Template
    {
        $template = $this->findTemplate($id);
        if (!$template) {
            throw new TemplateException('Template not found');
        }

        // Create version before update
        $this->createTemplateVersion($template);

        // Validate update data
        $validatedData = $this->validator->validate($data, [
            'name' => 'string|max:255',
            'content' => 'string',
            'type' => 'in:page,partial,layout',
            'variables' => 'array'
        ]);

        DB::beginTransaction();
        try {
            if (isset($validatedData['content'])) {
                $this->validateTemplateSecurity($validatedData['content']);
            }

            $template->update($validatedData);

            // Recompile template if content changed
            if (isset($validatedData['content'])) {
                $this->compiler->compileAndStore($template);
            }

            DB::commit();
            $this->cache->tags(['templates'])->flush();

            return $template;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateException('Template update failed: ' . $e->getMessage());
        }
    }

    protected function processTemplateCompilation(string $template): CompiledTemplate
    {
        // Validate template security before compilation
        $this->validateTemplateSecurity($template);

        try {
            // Compile template
            $compiled = $this->compiler->compile($template);

            // Validate compiled output
            $this->validateCompiledTemplate($compiled);

            return new CompiledTemplate($compiled);

        } catch (\Exception $e) {
            throw new TemplateException('Template compilation failed: ' . $e->getMessage());
        }
    }

    protected function validateTemplateSecurity(string $content): void
    {
        // Check for unsafe patterns
        if ($this->containsUnsafePatterns($content)) {
            throw new SecurityException('Template contains unsafe patterns');
        }

        // Validate template syntax
        if (!$this->compiler->validateSyntax($content)) {
            throw new TemplateException('Invalid template syntax');
        }
    }

    protected function containsUnsafePatterns(string $content): bool
    {
        $unsafePatterns = [
            '/\{\{.*\$[^}]+\}\}/', // Potentially unsafe variable output
            '/@php/i',             // Raw PHP code
            '/eval\s*\(/i',        // Eval attempts
            '/\$\{.*\}/',          // Shell expansion syntax
            '/\{\{.*file.*\}\}/i'  // File operations
        ];

        foreach ($unsafePatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    protected function validateCompiledTemplate(string $compiled): void
    {
        // Validate compiled template structure
        if (!$this->compiler->validateCompiled($compiled)) {
            throw new TemplateException('Invalid compiled template structure');
        }

        // Check for potential security issues in compiled code
        if ($this->containsUnsafePatterns($compiled)) {
            throw new SecurityException('Compiled template contains unsafe patterns');
        }
    }

    protected function getTemplateCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }

    protected function sanitizeTemplateData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    protected function createTemplateVersion(Template $template): void
    {
        TemplateVersion::create([
            'template_id' => $template->id,
            'content' => $template->content,
            'version' => $this->getNextVersionNumber($template),
            'created_by' => auth()->id()
        ]);
    }

    protected function getNextVersionNumber(Template $template): int
    {
        return $template->versions()->max('version') + 1;
    }

    protected function findTemplate(int $id): ?Template
    {
        return Template::with(['versions', 'components'])
            ->findOrFail($id);
    }
}
