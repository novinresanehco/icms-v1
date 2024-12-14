<?php

namespace App\Core\Template;

class TemplateEngine implements TemplateEngineInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidatorService $validator;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidatorService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string 
    {
        return $this->executeProtected(function() use ($template, $data) {
            $validated = $this->validateData($data);
            $compiled = $this->compileTemplate($template, $validated);
            return $this->renderWithSecurity($compiled, $validated);
        });
    }

    private function executeProtected(callable $operation): string 
    {
        try {
            DB::beginTransaction();
            $result = $operation();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function validateData(array $data): array 
    {
        return $this->validator->validateTemplateData($data, [
            'xss' => true,
            'sanitize' => true,
            'escape' => true
        ]);
    }

    private function compileTemplate(string $template, array $data): string 
    {
        return $this->cache->remember("template.$template", function() use ($template, $data) {
            $compiled = $this->compiler->compile($template);
            $this->security->validateTemplate($compiled);
            return $compiled;
        });
    }

    private function renderWithSecurity(string $compiled, array $data): string 
    {
        $context = $this->security->createRenderContext();
        return $this->security->renderProtected($compiled, $data, $context);
    }
}

class ContentRenderer implements ContentRendererInterface 
{
    private TemplateEngine $engine;
    private SecurityManager $security;
    private MediaHandler $media;

    public function renderContent(Content $content): string 
    {
        $this->security->validateContent($content);
        
        $template = $this->resolveTemplate($content);
        $data = $this->prepareData($content);
        
        return $this->engine->render($template, $data);
    }

    private function resolveTemplate(Content $content): string 
    {
        return $this->security->verifyTemplate(
            $content->getTemplate() ?? 'default'
        );
    }

    private function prepareData(Content $content): array 
    {
        return [
            'content' => $this->security->sanitizeContent($content),
            'media' => $this->media->getSecureUrls($content->media),
            'meta' => $this->security->sanitizeMeta($content->meta)
        ];
    }
}

class MediaHandler implements MediaHandlerInterface 
{
    private SecurityManager $security;
    private StorageManager $storage;

    public function getSecureUrls(Collection $media): array 
    {
        return $media->map(function($item) {
            return $this->generateSecureUrl($item);
        })->toArray();
    }

    private function generateSecureUrl(Media $media): string 
    {
        $token = $this->security->generateMediaToken($media);
        return $this->storage->getSignedUrl($media->path, $token);
    }
}

interface TemplateEngineInterface 
{
    public function render(string $template, array $data = []): string;
}

interface ContentRendererInterface 
{
    public function renderContent(Content $content): string;
}

interface MediaHandlerInterface 
{
    public function getSecureUrls(Collection $media): array;
}
