<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Template\Services\{CacheService, CompilerService, ValidationService};
use App\Core\Template\Models\Template;
use Illuminate\Support\Facades\DB;
use App\Core\Template\Events\TemplateEvent;

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private CacheService $cache;
    private CompilerService $compiler;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        CacheService $cache,
        CompilerService $compiler,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->validator = $validator;
    }

    public function render(string $templateId, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleRender($templateId, $data),
            ['operation' => 'template_render']
        );
    }

    public function compile(string $templateId): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->handleCompile($templateId),
            ['operation' => 'template_compile']
        );
    }

    public function create(array $data): Template
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleCreate($data),
            ['operation' => 'template_create']
        );
    }

    public function update(string $id, array $data): Template
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleUpdate($id, $data),
            ['operation' => 'template_update']
        );
    }

    private function handleRender(string $templateId, array $data): string
    {
        return $this->cache->remember("template.$templateId", function() use ($templateId, $data) {
            $template = Template::findOrFail($templateId);
            
            if (!$template->is_compiled) {
                $this->handleCompile($templateId);
            }
            
            $this->validator->validateData($data, $template->data_schema);
            
            return $this->compiler->render($template->compiled_content, $data);
        });
    }

    private function handleCompile(string $templateId): void
    {
        DB::beginTransaction();
        try {
            $template = Template::findOrFail($templateId);
            
            $compiledContent = $this->compiler->compile($template->content);
            
            $template->update([
                'compiled_content' => $compiledContent,
                'is_compiled' => true,
                'compiled_at' => now()
            ]);
            
            event(new TemplateEvent('compiled', $template));
            
            $this->cache->invalidate("template.$templateId");
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handleCreate(array $data): Template
    {
        $validated = $this->validator->validateTemplate($data);
        
        DB::beginTransaction();
        try {
            $template = Template::create($validated);
            
            if ($data['compile_now'] ?? false) {
                $compiledContent = $this->compiler->compile($template->content);
                $template->update([
                    'compiled_content' => $compiledContent,
                    'is_compiled' => true,
                    'compiled_at' => now()
                ]);
            }
            
            event(new TemplateEvent('created', $template));
            
            DB::commit();
            return $template;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handleUpdate(string $id, array $data): Template
    {
        $validated = $this->validator->validateTemplate($data);
        
        DB::beginTransaction();
        try {
            $template = Template::findOrFail($id);
            $template->update($validated);
            
            if ($data['compile_now'] ?? false) {
                $compiledContent = $this->compiler->compile($template->content);
                $template->update([
                    'compiled_content' => $compiledContent,
                    'is_compiled' => true,
                    'compiled_at' => now()
                ]);
            } else {
                $template->update(['is_compiled' => false]);
            }
            
            event(new TemplateEvent('updated', $template));
            
            $this->cache->invalidate("template.$id");
            
            DB::commit();
            return $template;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function clearCache(string $templateId = null): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->cache->clearTemplateCache($templateId),
            ['operation' => 'template_cache_clear']
        );
    }

    public function validateTemplate(string $templateId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->validator->validateTemplateStructure($templateId),
            ['operation' => 'template_validation']
        );
    }
}
