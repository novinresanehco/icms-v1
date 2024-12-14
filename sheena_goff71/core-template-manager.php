<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{DB, Cache, View};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Interfaces\{
    TemplateManagerInterface,
    StorageInterface
};
use App\Core\Exceptions\{
    TemplateException,
    SecurityException,
    ValidationException
};

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private StorageInterface $storage;
    private array $config;

    private const CACHE_PREFIX = 'template:';
    private const CACHE_TTL = 3600;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        StorageInterface $storage,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function create(array $data, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($data, $context) {
            $this->validateTemplate($data);
            
            $template = $this->prepareTemplate($data);
            $compiledTemplate = $this->compileTemplate($template);
            
            $storedTemplate = $this->storage->store([
                'template' => $template,
                'compiled' => $compiledTemplate,
                'metadata' => $this->createMetadata($context)
            ]);
            
            $this->invalidateCache();
            return $storedTemplate;
        }, $context);
    }

    public function render(int $id, array $data, array $context): string
    {
        return $this->security->executeSecureOperation(function() use ($id, $data, $context) {
            $template = $this->loadTemplate($id);
            $this->validateRenderData($data);
            
            $renderContext = $this->createRenderContext($data, $context);
            return $this->renderTemplate($template, $renderContext);
        }, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($id, $data, $context) {
            $template = $this->storage->find($id);
            if (!$template) {
                throw new TemplateException('Template not found');
            }
            
            $this->validateTemplate($data);
            $this->validateVersionControl($template, $data);
            
            $updatedTemplate = $this->prepareTemplate($data);
            $compiledTemplate = $this->compileTemplate($updatedTemplate);
            
            $storedTemplate = $this->storage->update($id, [
                'template' => $updatedTemplate,
                'compiled' => $compiledTemplate,
                'metadata' => $this->updateMetadata($template['metadata'], $context)
            ]);
            
            $this->invalidateCache();
            return $storedTemplate;
        }, $context);
    }

    protected function validateTemplate(array $data): void
    {
        if (!isset($data['content']) || !is_string($data['content'])) {
            throw new ValidationException('Invalid template content');
        }

        if ($this->containsUnsafeContent($data['content'])) {
            throw new SecurityException('Template contains unsafe content');
        }

        if (!$this->validator->validateInput($data)) {
            throw new ValidationException('Invalid template data');
        }
    }

    protected function validateRenderData(array $data): void
    {
        if (!$this->validator->validateInput($data)) {
            throw new ValidationException('Invalid render data');
        }

        foreach ($data as $key => $value) {
            if ($this->isUnsafeValue($value)) {
                throw new SecurityException("Unsafe value for key: {$key}");
            }
        }
    }

    protected function validateVersionControl(array $existing, array $new): void
    {
        if ($existing['version'] !== ($new['version'] ?? null)) {
            throw new TemplateException('Version mismatch');
        }
    }

    protected function prepareTemplate(array $data): array
    {
        return [
            'content' => $data['content'],
            'version' => ($data['version'] ?? 0) + 1,
            'hash' => $this->generateTemplateHash($data['content'])
        ];
    }

    protected function compileTemplate(array $template): string
    {
        try {
            return View::compileString($template['content']);
        } catch (\Throwable $e) {
            throw new TemplateException('Template compilation failed: ' . $e->getMessage());
        }
    }

    protected function renderTemplate(array $template, array $context): string
    {
        $cacheKey = $this->getRenderCacheKey($template, $context);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($template, $context) {
            try {
                return View::make(
                    'string:' . $template['compiled'],
                    $this->sanitizeRenderContext($context)
                )->render();
            } catch (\Throwable $e) {
                throw new TemplateException('Template rendering failed: ' . $e->getMessage());
            }
        });
    }

    protected function loadTemplate(int $id): array
    {
        $template = $this->storage->find($id);
        if (!$template) {
            throw new TemplateException('Template not found');
        }
        return $template;
    }

    protected function createRenderContext(array $data, array $context): array
    {
        return array_merge(
            $this->getSafeGlobals(),
            $this->sanitizeRenderData($data),
            [
                'user' => $context['user'] ?? null,
                'timestamp' => time()
            ]
        );
    }

    protected function containsUnsafeContent(string $content): bool
    {
        $unsafePatterns = [
            '/<\?php/i',
            '/\{?\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\}?/',
            '/eval\s*\(/',
            '/exec\s*\(/',
            '/system\s*\(/'
        ];

        foreach ($unsafePatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    protected function isUnsafeValue($value): bool
    {
        if (is_string($value)) {
            return $this->containsUnsafeContent($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->isUnsafeValue($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function sanitizeRenderContext(array $context): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $context);
    }

    protected function sanitizeRenderData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            if (is_array($value)) {
                return $this->sanitizeRenderData($value);
            }
            return $value;
        }, $data);
    }

    protected function getSafeGlobals(): array
    {
        return [
            'config' => $this->config['template_globals'] ?? [],
            'helpers' => $this->config['template_helpers'] ?? []
        ];
    }

    protected function generateTemplateHash(string $content): string
    {
        return hash_hmac('sha256', $content, $this->config['app_key']);
    }

    protected function getRenderCacheKey(array $template, array $context): string
    {
        return self::CACHE_PREFIX . hash('sha256', json_encode([
            'template_id' => $template['id'],
            'template_version' => $template['version'],
            'context_hash' => hash('sha256', json_encode($context))
        ]));
    }

    protected function createMetadata(array $context): array
    {
        return [
            'created_by' => $context['user_id'] ?? null,
            'created_at' => time(),
            'updated_at' => time()
        ];
    }

    protected function updateMetadata(array $metadata, array $context): array
    {
        return array_merge($metadata, [
            'updated_by' => $context['user_id'] ?? null,
            'updated_at' => time()
        ]);
    }

    protected function invalidateCache(): void
    {
        Cache::tags([self::CACHE_PREFIX])->flush();
    }
}
