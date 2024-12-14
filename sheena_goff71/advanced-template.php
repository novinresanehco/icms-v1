<?php

namespace App\Core\Template\Advanced;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private TemplateCompiler $compiler;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        TemplateCompiler $compiler
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->compiler = $compiler;
    }

    public function display(string $template, array $data, array $options = []): string 
    {
        $this->security->enforceTemplateAccess($template);
        
        return $this->cache->remember(
            $this->getCacheKey($template, $data), 
            fn() => $this->renderSecure($template, $data, $options)
        );
    }

    public function renderSecure(string $template, array $data, array $options): string 
    {
        $sanitizedData = $this->security->sanitizeTemplateData($data);
        $compiledTemplate = $this->compiler->compile($template, $options);
        
        $rendered = $compiledTemplate->render($sanitizedData);
        $this->security->validateRenderedOutput($rendered);
        
        return $rendered;
    }

    public function partial(string $name, array $data = []): string 
    {
        $this->security->enforcePartialAccess($name);
        return $this->display("partials/{$name}", $data);
    }

    private function getCacheKey(string $template, array $data): string 
    {
        return "template_v2:{$template}:" . md5(serialize($data));
    }
}

class DynamicContentRenderer implements DynamicContentInterface 
{
    private SecurityManagerInterface $security;
    private array $contentProcessors = [];

    public function render(string $type, array $content): string 
    {
        $this->security->validateContentType($type);
        
        $processor = $this->contentProcessors[$type] 
            ?? throw new ProcessorNotFoundException();
            
        $processedContent = $processor->process($content);
        return $this->security->sanitizeOutput($processedContent);
    }

    public function registerProcessor(string $type, ContentProcessorInterface $processor): void 
    {
        $this->contentProcessors[$type] = $processor;
    }
}

interface TemplateManagerInterface {
    public function display(string $template, array $data, array $options = []): string;
    public function partial(string $name, array $data = []): string;
}

interface DynamicContentInterface {
    public function render(string $type, array $content): string;
    public function registerProcessor(string $type, ContentProcessorInterface $processor): void;
}

interface ContentProcessorInterface {
    public function process(array $content): string;
}

class ProcessorNotFoundException extends \RuntimeException {}
