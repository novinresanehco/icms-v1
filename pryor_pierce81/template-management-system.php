<?php

namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private CompilerInterface $compiler;
    private ValidatorInterface $validator;
    private array $extensions = [];

    public function render(string $template, array $data = []): string 
    {
        try {
            $this->security->validateCriticalOperation([
                'action' => 'template.render',
                'template' => $template,
                'context' => array_keys($data)
            ]);

            $this->validator->validateTemplateData($data);
            
            $compiledTemplate = $this->compileTemplate($template);
            $renderedContent = $this->renderTemplate($compiledTemplate, $data);
            
            $this->validator->validateOutput($renderedContent);
            
            return $renderedContent;

        } catch (\Exception $e) {
            $this->handleRenderError($e, $template);
            throw $e;
        }
    }

    public function compile(string $template): CompiledTemplate 
    {
        return $this->cache->tags(['templates'])->remember(
            $this->getCacheKey($template),
            config('cms.cache.ttl'),
            function() use ($template) {
                $source = $this->repository->getTemplate($template);
                return $this->compiler->compile($source);
            }
        );
    }

    public function registerExtension(string $name, callable $extension): void 
    {
        $this->security->validateCriticalOperation([
            'action' => 'template.extend',
            'extension' => $name
        ]);

        $this->extensions[$name] = $extension;
        $this->compiler->addExtension($name, $extension);
    }

    public function createTemplate(array $data): TemplateResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'template.create',
                'data' => $data
            ]);

            $validated = $this->validator->validate($data, [
                'name' => 'required|string|max:255',
                'content' => 'required|string',
                'type' => 'required|in:page,partial,layout',
                'meta' => 'array'
            ]);

            // Validate template syntax
            $this->compiler->validate($validated['content']);

            $template = $this->repository->create([
                'name' => $validated['name'],
                'content' => $validated['content'],
                'type' => $validated['type'],
                'meta' => $validated['meta'] ?? [],
                'checksum' => $this->generateChecksum($validated['content']),
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);

            $this->cache->tags(['templates'])->put(
                $this->getCacheKey($template->name),
                $template,
                config('cms.cache.ttl')
            );

            DB::commit();
            return new TemplateResult($template);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateTemplate(string $name, array $data): TemplateResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'template.update',
                'template' => $name,
                'data' => $data
            ]);

            $template = $this->repository->findByName($name);
            
            $validated = $this->validator->validate($data, [
                'content' => 'string',
                'meta' => 'array'
            ]);

            if (isset($validated['content'])) {
                $this->compiler->validate($validated['content']);
            }

            $updated = $this->repository->update($template->id, [
                'content' => $validated['content'] ?? $template->content,
                'meta' => array_merge($template->meta, $validated['meta'] ?? []),
                'checksum' => isset($validated['content']) 
                    ? $this->generateChecksum($validated['content'])
                    : $template->checksum,
                'updated_at' => now()
            ]);

            $this->cache->tags(['templates'])->forget($this->getCacheKey($name));

            DB::commit();
            return new TemplateResult($updated);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteTemplate(string $name): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'template.delete',
                'template' => $name
            ]);

            $template = $this->repository->findByName($name);
            
            if ($template->type === 'layout' && $this->isTemplateInUse($template->id)) {
                throw new TemplateInUseException('Cannot delete layout template in use');
            }

            $this->repository->delete($template->id);
            $this->cache->tags(['templates'])->forget($this->getCacheKey($name));

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function compileTemplate(string $template): CompiledTemplate 
    {
        return $this->cache->tags(['templates'])->remember(
            "compiled.{$template}",
            config('cms.cache.ttl'),
            fn() => $this->compiler->compile($this->repository->getTemplate($template))
        );
    }

    private function renderTemplate(CompiledTemplate $template, array $data): string 
    {
        return $template->render($this->sanitizeData($data));
    }

    private function sanitizeData(array $data): array 
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    private function generateChecksum(string $content): string 
    {
        return hash('sha256', $content);
    }

    private function getCacheKey(string $name): string 
    {
        return "template.{$name}";
    }

    private function isTemplateInUse(int $id): bool 
    {
        return $this->repository->getTemplateUsage($id) > 0;
    }

    private function handleRenderError(\Exception $e, string $template): void 
    {
        Log::error('Template render failed', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
