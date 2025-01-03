<?php

namespace App\Core\Template;

class TemplateManager implements TemplateInterface
{
    private CacheService $cache;
    private SecurityManager $security;
    private TemplateRepository $repository;
    private TemplateCompiler $compiler;

    public function render(string $template, array $data = []): string
    {
        return $this->cache->remember(
            ['template', $template, md5(serialize($data))],
            function() use ($template, $data) {
                $compiled = $this->compile($template);
                return $this->renderCompiled($compiled, $data);
            }
        );
    }

    public function compile(string $template): CompiledTemplate
    {
        $source = $this->repository->getTemplate($template);
        return $this->compiler->compile($source);
    }

    private function renderCompiled(CompiledTemplate $template, array $data): string
    {
        try {
            return $template->render($this->security->sanitizeData($data));
        } catch (\Exception $e) {
            throw new TemplateException('Failed to render template', 0, $e);
        }
    }
}

class TemplateCompiler
{
    private array $directives = [];
    private SecurityManager $security;

    public function compile(string $source): CompiledTemplate
    {
        $sanitized = $this->security->sanitizeTemplate($source);
        $parsed = $this->parse($sanitized);
        $validated = $this->validate($parsed);
        return new CompiledTemplate($validated);
    }

    private function parse(string $template): array
    {
        $tokens = [];
        foreach ($this->tokenize($template) as $token) {
            $tokens[] = $this->processToken($token);
        }
        return $tokens;
    }

    private function validate(array $parsed): array
    {
        foreach ($parsed as $node) {
            if (!$this->isValidNode($node)) {
                throw new TemplateException('Invalid template syntax');
            }
        }
        return $parsed;
    }

    private function processToken(string $token): array
    {
        if ($directive = $this->matchDirective($token)) {
            return [
                'type' => 'directive',
                'name' => $directive['name'],
                'params' => $directive['params']
            ];
        }

        return [
            'type' => 'text',
            'content' => $token
        ];
    }

    private function isValidNode(array $node): bool
    {
        if ($node['type'] === 'directive') {
            return isset($this->directives[$node['name']]);
        }
        return true;
    }
}

class TemplateRepository extends BaseRepository
{
    protected ValidationService $validator;
    protected CacheService $cache;

    public function getTemplate(string $name): string
    {
        return $this->cache->remember(
            ['template_source', $name],
            fn() => $this->findTemplate($name)
        );
    }

    private function findTemplate(string $name): string
    {
        $template = $this->model->where('name', $name)->first();
        if (!$template) {
            throw new TemplateException("Template not found: {$name}");
        }
        return $template->source;
    }

    public function store(string $name, string $source): void
    {
        DB::transaction(function() use ($name, $source) {
            $this->model->create([
                'name' => $name,
                'source' => $source
            ]);
            $this->cache->forget(['template_source', $name]);
        });
    }

    public function update(string $name, string $source): void
    {
        DB::transaction(function() use ($name, $source) {
            $template = $this->model->where('name', $name)->firstOrFail();
            $template->update(['source' => $source]);
            $this->cache->forget(['template_source', $name]);
        });
    }

    public function delete(string $name): void
    {
        DB::transaction(function() use ($name) {
            $this->model->where('name', $name)->delete();
            $this->cache->forget(['template_source', $name]);
        });
    }
}
