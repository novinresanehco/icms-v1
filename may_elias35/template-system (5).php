<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, Blade};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\TemplateException;

class TemplateManager
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private CacheManager $cache;

    public function render(string $templateId, array $data, array $context): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRender($templateId, $data),
            $context
        );
    }

    private function executeRender(string $templateId, array $data): string
    {
        $template = $this->getTemplate($templateId);
        $this->validator->validateTemplateData($data, $template->schema);
        
        $cacheKey = $this->getCacheKey($templateId, $data);
        
        return $this->cache->remember($cacheKey, 3600, function() use ($template, $data) {
            $compiled = $this->compileTemplate($template, $data);
            $this->auditLogger->logTemplateRender($template, $data);
            return $compiled;
        });
    }

    public function createTemplate(array $templateData, array $context): TemplateEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreateTemplate($templateData),
            $context
        );
    }

    private function executeCreateTemplate(array $templateData): TemplateEntity
    {
        $validated = $this->validator->validate($templateData, [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'schema' => 'required|array',
            'type' => 'required|string'
        ]);

        return DB::transaction(function() use ($validated) {
            $this->validateSyntax($validated['content']);
            
            $template = $this->repository->create($validated);
            $this->compileAndCache($template);
            $this->auditLogger->logTemplateCreation($template);
            
            return $template;
        });
    }

    private function getTemplate(string $templateId): TemplateEntity
    {
        return $this->cache->remember(
            "template:$templateId",
            3600,
            fn() => $this->repository->findOrFail($templateId)
        );
    }

    private function compileTemplate(TemplateEntity $template, array $data): string
    {
        try {
            $compiled = Blade::compileString($template->content);
            return View::make('template', [
                'content' => $compiled,
                'data' => $this->sanitizeData($data)
            ])->render();
        } catch (\Throwable $e) {
            $this->auditLogger->logTemplateError($template, $e);
            throw new TemplateException('Template compilation failed', 0, $e);
        }
    }

    private function validateSyntax(string $content): void
    {
        try {
            Blade::compileString($content);
        } catch (\Throwable $e) {
            throw new TemplateException('Invalid template syntax', 0, $e);
        }
    }

    private function compileAndCache(TemplateEntity $template): void
    {
        $compiled = Blade::compileString($template->content);
        $this->cache->set(
            "template_compiled:{$template->id}",
            $compiled,
            3600
        );
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    private function getCacheKey(string $templateId, array $data): string
    {
        return "template_rendered:{$templateId}:" . md5(serialize($data));
    }
}

class TemplateRepository
{
    public function create(array $data): TemplateEntity
    {
        return TemplateEntity::create($data);
    }

    public function findOrFail(string $id): TemplateEntity
    {
        return TemplateEntity::findOrFail($id);
    }

    public function update(string $id, array $data): TemplateEntity
    {
        $template = $this->findOrFail($id);
        $template->update($data);
        return $template;
    }
}

class TemplateEntity extends Model
{
    protected $fillable = [
        'name',
        'content',
        'schema',
        'type'
    ];

    protected $casts = [
        'schema' => 'array'
    ];

    public function versions()
    {
        return $this->hasMany(TemplateVersion::class);
    }
}

class TemplateVersion extends Model
{
    protected $fillable = [
        'template_id',
        'content',
        'created_by'
    ];

    public function template()
    {
        return $this->belongsTo(TemplateEntity::class);
    }
}
