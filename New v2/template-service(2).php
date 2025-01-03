<?php

namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface
{
    private TemplateRepository $repository;
    private TemplateCompiler $compiler;
    private CacheService $cache;
    private SecurityService $security;
    private ValidationService $validator;
    private EventManager $events;

    public function __construct(
        TemplateRepository $repository,
        TemplateCompiler $compiler,
        CacheService $cache,
        SecurityService $security,
        ValidationService $validator,
        EventManager $events
    ) {
        $this->repository = $repository;
        $this->compiler = $compiler;
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->cache->remember(
            $this->getCacheKey($template, $data),
            function() use ($template, $data) {
                return $this->renderTemplate($template, $data);
            }
        );
    }

    public function compile(string $template): CompiledTemplate
    {
        $this->validateTemplate($template);
        
        $compiled = $this->compiler->compile($template);
        
        $this->validateCompiled($compiled);
        
        return $compiled;
    }

    public function store(string $name, string $content): Template
    {
        return DB::transaction(function() use ($name, $content) {
            $this->validateTemplate($content);
            
            // Compile to verify syntax
            $this->compiler->compile($content);
            
            $template = $this->repository->create([
                'name' => $name,
                'content' => $this->security->encryptData($content)
            ]);
            
            $this->cache->tags(['templates'])->flush();
            
            $this->events->dispatch(new TemplateStored($template));
            
            return $template;
        });
    }

    protected function renderTemplate(string $template, array $data): string
    {
        $compiled = $this->compile($template);
        
        $data = $this->prepareData($data);
        
        return $compiled->render($data);
    }

    protected function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateException('Invalid template syntax');
        }
    }

    protected function validateCompiled(CompiledTemplate $compiled): void
    {
        if (!$compiled->isValid()) {
            throw new CompilationException('Template compilation failed');
        }
    }

    protected function prepareData(array $data): array
    {
        return array_map(function($value) {
            return is_string($value) ? e($value) : $value;
        }, $data);
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template:%s:%s',
            md5($template),
            md5(serialize($data))
        );
    }
}

class TemplateCompiler implements CompilerInterface
{
    private SecurityService $security;
    private ValidationService $validator;

    public function compile(string $template): CompiledTemplate
    {
        // Remove potential security risks
        $template = $this->security->sanitizeTemplate($template);
        
        // Validate syntax
        $this->validator->validateSyntax($template);
        
        // Parse template
        $parsed = $this->parseTemplate($template);
        
        // Compile to PHP
        $php = $this->compileToPhp($parsed);
        
        // Validate compiled code
        $this->validateCompiled($php);
        
        return new CompiledTemplate($php);
    }

    protected function parseTemplate(string $template): array
    {
        $tokens = $this->tokenize($template);
        return $this->parse($tokens);
    }

    protected function compileToPhp(array $parsed): string
    {
        $compiler = new PhpCompiler();
        return