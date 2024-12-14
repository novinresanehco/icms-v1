<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Protection\SystemProtection;
use App\Core\Data\TransactionManager;

class TemplateManager
{
    private SecurityManager $security;
    private SystemProtection $protection;
    private TransactionManager $transaction;
    private CacheManager $cache;
    private CompilationService $compiler;

    public function __construct(
        SecurityManager $security,
        SystemProtection $protection,
        TransactionManager $transaction,
        CacheManager $cache,
        CompilationService $compiler
    ) {
        $this->security = $security;
        $this->protection = $protection;
        $this->transaction = $transaction;
        $this->cache = $cache;
        $this->compiler = $compiler;
    }

    public function createTemplate(array $data, array $context): Template
    {
        return $this->security->executeCriticalOperation(function() use ($data, $context) {
            return $this->protection->executeProtectedOperation(function() use ($data, $context) {
                return $this->transaction->executeTransaction(function() use ($data) {
                    // Validate template data
                    $validated = $this->validator->validateTemplate($data);
                    
                    // Compile template
                    $compiled = $this->compiler->compile($validated['content']);
                    
                    // Create template
                    $template = Template::create([
                        'name' => $validated['name'],
                        'content' => $validated['content'],
                        'compiled' => $compiled,
                        'metadata' => $validated['metadata'] ?? []
                    ]);
                    
                    // Clear template cache
                    $this->cache->invalidateGroup('templates');
                    
                    return $template;
                }, $context);
            }, $context);
        }, $context);
    }

    public function renderTemplate(int $id, array $data, array $context): string
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $context) {
            // Get template from cache or database
            $template = $this->cache->remember("template:{$id}", function() use ($id) {
                return $this->findOrFail($id);
            });
            
            // Validate render data
            $validated = $this->validator->validateRenderData($data);
            
            // Render with protection
            return $this->protection->executeProtectedOperation(function() use ($template, $validated) {
                return $this->compiler->render($template->compiled, $validated);
            }, $context);
        }, $context);
    }

    public function updateTemplate(int $id, array $data, array $context): Template
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $context) {
            return $this->protection->executeProtectedOperation(function() use ($id, $data, $context) {
                return $this->transaction->executeTransaction(function() use ($id, $data) {
                    $template = $this->findOrFail($id);
                    
                    // Validate update data
                    $validated = $this->validator->validateTemplateUpdate($data);
                    
                    // Recompile if content changed
                    if (isset($validated['content'])) {
                        $validated['compiled'] = $this->compiler->compile($validated['content']);
                    }
                    
                    // Update template
                    $template->update($validated);
                    
                    // Clear cache
                    $this->cache->invalidateGroup('template:' . $id);
                    $this->cache->invalidateGroup('templates');
                    
                    return $template->fresh();
                }, $context);
            }, $context);
        }, $context);
    }

    protected function findOrFail(int $id): Template 
    {
        if (!$template = Template::find($id)) {
            throw new TemplateNotFoundException("Template not found: {$id}");
        }
        return $template;
    }
}
