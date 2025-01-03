<?php
namespace App\Core\Template;

/**
 * Core template engine with comprehensive security
 */
class TemplateEngine implements TemplateEngineInterface
{
    private SecurityManager $security;
    private TemplateRepository $templates;
    private CacheManager $cache;
    private AuditLogger $audit;
    private CompilerService $compiler;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $templates,
        CacheManager $cache,
        AuditLogger $audit,
        CompilerService $compiler
    ) {
        $this->security = $security;
        $this->templates = $templates;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->compiler = $compiler;
    }

    public function render(string $template, array $data, SecurityContext $context): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation(
                $template,
                $data,
                $this->templates,
                $this->cache,
                $this->compiler,
                $this->audit
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
                $this->audit
            ),
            $context
        );
    }

    public function store(string $name, string $content, SecurityContext $context): Template
    {
        return $this->security->executeCriticalOperation(
            new StoreTemplateOperation(
                $name,
                $content,
                $this->templates,
                $this->cache,
                $this->audit
            ),
            $context
        );
    }
}

class RenderTemplateOperation extends CriticalOperation
{
    private string $template;
    private array $data;
    private TemplateRepository $templates;
    private CacheManager $cache;
    private CompilerService $compiler;
    private AuditLogger $audit;

    public function execute(): string
    {
        return $this->cache->remember(
            $this->getCacheKey(),
            function() {
                $template = $this->loadTemplate();
                $compiled = $this->compiler->compile($template);
                $rendered = $compiled->render($this->data);
                
                $this->audit->logTemplateRender($template);
                
                return $rendered;
            }
        );
    }

    private function loadTemplate(): Template
    {
        $template = $this->templates->findByName($this->template);
        if (!$template) {
            throw new TemplateNotFoundException("Template not found: {$this->template}");
        }
        return $template;
    }

    private function getCacheKey(): string
    {
        return "template.render.{$this->template}." . md5(serialize($this->data));
    }

    public function getRequiredPermissions(): array
    {
        return ['template.render'];
    }
}

class CompileTemplateOperation extends CriticalOperation
{
    private string $template;
    private CompilerService $compiler;
    private AuditLogger $audit;

    public function execute(): CompiledTemplate
    {
        $compiled = $this->compiler->compile($this->template);
        $this->audit->logTemplateCompile($this->template);
        return $compiled;
    }

    public function getRequiredPermissions(): array
    {
        return ['template.compile'];
    }
}

class StoreTemplateOperation extends CriticalOperation
{
    private string $name;
    private string $content;
    private TemplateRepository $templates;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): Template
    {
        $template = $this->templates->create([
            'name' => $this->name,
            'content' => $this->content,
            'hash' => $this->generateHash()
        ]);

        $this->cache->invalidatePattern("template.*");
        $this->audit->logTemplateStore($template);
        
        return $template;
    }

    private function generateHash(): string
    {
        return hash('sha256', $this->content);
    }

    public function getRequiredPermissions(): array
    {
        return ['template.create'];
    }
}

class CompilerService
{
    private SecurityManager $security;
    private ValidationService $validator;

    public function compile(string $template): CompiledTemplate
    {
        // Validate template security
        $this->validateSecurity($template);

        // Parse template
        $ast = $this->parse($template);

        // Optimize AST
        $optimized = $this->optimize($ast);

        // Generate code
        $code = $this->generate($optimized);

        return new CompiledTemplate($code);
    }

    private function validateSecurity(string $template): void
    {
        // Check for dangerous constructs
        if ($this->containsDangerousCode($template)) {
            throw new SecurityException('Template contains dangerous code');
        }

        // Validate template structure
        if (!$this->validator->validateTemplate($template)) {
            throw new ValidationException('Invalid template structure');
        }
    }

    private function containsDangerousCode(string $template): bool
    {
        $dangerous = [
            'eval',
            'exec',
            'system',
            '`',
            '<?php',
            '<?=',
            '<%'
        ];

        foreach ($dangerous as $pattern) {
            if (stripos($template, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function parse(string $template): array
    {
        // Implementation of template parsing
        return [];
    }

    private function optimize(array $ast): array
    {
        // Implementation of AST optimization
        return $ast;
    }

    private function generate(array $ast): string
    {
        // Implementation of code generation
        return '';
    }
}

class TemplateRepository extends BaseRepository
{
    protected function model(): string
    {
        return Template::class;
    }

    public function findByName(string $name): ?Template
    {
        return $this->cache->remember(
            "template.name.{$name}",
            fn() => $this->model->where('name', $name)->first()
        );
    }

    public function create(array $data): Template
    {
        return DB::transaction(function() use ($data) {
            $template = $this->model->create($data);
            $this->createVersion($template);
            return $template;
        });
    }

    private function createVersion(Template $template): void
    {
        TemplateVersion::create([
            'template_id' => $template->id,
            'content' => $template->content,
            'hash' => $template->hash
        ]);
    }
}
