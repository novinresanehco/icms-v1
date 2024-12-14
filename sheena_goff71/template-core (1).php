<?php

namespace App\Core\Template;

class TemplateEngine implements TemplateInterface 
{
    private CacheManager $cache;
    private SecurityValidator $validator;
    private ComponentRegistry $components;
    private RenderingQueue $queue;
    
    public function render(string $template, array $data): string 
    {
        $hash = $this->validator->hashContent($template . serialize($data));
        
        return $this->cache->remember($hash, function() use ($template, $data) {
            return $this->queue->process(
                $this->validator->validate($template),
                $this->validator->sanitizeData($data)
            );
        });
    }
}

class ComponentRegistry
{
    private array $components = [];
    
    public function process(string $content, array $data): string 
    {
        foreach ($this->components as $component) {
            $content = $component->render($content, $data);
        }
        return $content;
    }
}

class RenderingQueue
{
    private array $handlers = [];
    
    public function process(string $content, array $data): string 
    {
        foreach ($this->handlers as $handler) {
            $content = $handler->execute($content, $data);
        }
        return $content;
    }
}

interface TemplateInterface 
{
    public function render(string $template, array $data): string;
}

interface ComponentInterface 
{
    public function render(string $content, array $data): string;
}

final class SecurityValidator
{
    public function validate(string $template): string 
    {
        if (!$this->isSecure($template)) {
            throw new TemplateSecurityException();
        }
        return $template;
    }

    public function sanitizeData(array $data): array 
    {
        return array_map([$this, 'sanitizeValue'], $data);
    }

    private function isSecure(string $content): bool 
    {
        return !preg_match('/\{\{.*\}\}/', $content);
    }
}
