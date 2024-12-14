<?php

namespace App\Core\Template;

class TemplateEngine implements TemplateEngineInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $protectedBlocks = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function render(string $template, array $data): string 
    {
        return $this->security->executeCriticalOperation(
            new RenderOperation($template, $data),
            function() use ($template, $data) {
                $compiled = $this->cache->remember(
                    "template.{$template}",
                    fn() => $this->compile($template)
                );
                
                $validated = $this->validator->validateTemplateData($data);
                return $this->renderCompiled($compiled, $validated);
            }
        );
    }

    private function compile(string $template): CompiledTemplate 
    {
        $ast = $this->parser->parse($template);
        $this->validator->validateTemplateStructure($ast);
        return $this->compiler->compile($ast);
    }

    private function renderCompiled(CompiledTemplate $compiled, array $data): string 
    {
        $context = new RenderContext($data, $this->protectedBlocks);
        return $compiled->render($context);
    }
}

class ContentRenderer implements ContentRendererInterface 
{
    private TemplateEngine $engine;
    private SecurityManager $security;
    private MediaService $media;

    public function renderContent(Content $content, Template $template): string 
    {
        return $this->security->executeCriticalOperation(
            new ContentRenderOperation($content, $template),
            fn() => $this->doRenderContent($content, $template)
        );
    }

    private function doRenderContent(Content $content, Template $template): string 
    {
        $data = [
            'content' => $this->prepareContent($content),
            'media' => $this->media->getContentMedia($content),
            'meta' => $content->getMeta()
        ];

        return $this->engine->render($template->getName(), $data);
    }

    private function prepareContent(Content $content): array 
    {
        return [
            'body' => $this->security->sanitizeHtml($content->getBody()),
            'title' => $this->security->sanitizeText($content->getTitle()),
            'attributes' => $this->validator->validateAttributes($content->getAttributes())
        ];
    }
}

class MediaGallery implements MediaGalleryInterface
{
    private SecurityManager $security;
    private MediaProcessor $processor;
    private CacheManager $cache;

    public function renderGallery(array $media, GalleryConfig $config): string 
    {
        return $this->security->executeCriticalOperation(
            new GalleryRenderOperation($media, $config),
            fn() => $this->doRenderGallery($media, $config)
        );
    }

    private function doRenderGallery(array $media, GalleryConfig $config): string 
    {
        $processed = array_map(
            fn($item) => $this->processor->process($item, $config),
            $this->validator->validateMediaItems($media)
        );

        return $this->cache->remember(
            $this->getCacheKey($processed, $config),
            fn() => $this->renderProcessedGallery($processed, $config)
        );
    }

    private function renderProcessedGallery(array $media, GalleryConfig $config): string 
    {
        $template = $this->getGalleryTemplate($config);
        return $this->engine->render($template, [
            'media' => $media,
            'config' => $config->toArray()
        ]);
    }
}

class UIComponentManager implements UIComponentInterface
{
    private SecurityManager $security;
    private ComponentRegistry $registry;
    private ValidationService $validator;

    public function renderComponent(string $name, array $props): string 
    {
        return $this->security->executeCriticalOperation(
            new ComponentRenderOperation($name, $props),
            fn() => $this->doRenderComponent($name, $props)
        );
    }

    private function doRenderComponent(string $name, array $props): string 
    {
        $component = $this->registry->get($name);
        $validatedProps = $this->validator->validateProps($props, $component->getPropsSchema());
        
        return $component->render($validatedProps);
    }
}
