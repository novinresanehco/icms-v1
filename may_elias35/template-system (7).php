<?php

namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManagerInterface $security;
    private TemplateRepository $repository;
    private TemplateRenderer $renderer;
    private CacheManager $cache;

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeProtected(
            fn() => $this->doRender($template, $data),
            ['action' => 'render_template', 'template' => $template]
        );
    }

    private function doRender(string $template, array $data): string
    {
        $template = $this->cache->remember(
            "template.$template",
            fn() => $this->repository->findByName($template)
        );

        return $this->renderer->render($template, $this->sanitizeData($data));
    }

    private function sanitizeData(array $data): array 
    {
        return $this->security->validateData($data, [
            'html' => ['strip_tags', 'encode_entities'],
            'scripts' => ['remove_unsafe_content'],
            'urls' => ['validate_url', 'sanitize_path']
        ]);
    }
}

class TemplateRenderer
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;

    public function render(Template $template, array $data): string
    {
        // Pre-render security checks
        $this->validator->validateTemplate($template);
        
        // Render with security context
        return $this->security->executeProtected(
            fn() => $this->processTemplate($template, $data),
            ['action' => 'process_template']
        );
    }

    private function processTemplate(Template $template, array $data): string 
    {
        return view()
            ->file($template->path, $data)
            ->render();
    }
}

interface MediaGalleryInterface 
{
    public function renderGallery(array $media): string;
    public function validateMedia(array $media): bool;
    public function processThumbnails(UploadedFile $file): array;
}

class MediaGalleryManager implements MediaGalleryInterface
{
    private SecurityManagerInterface $security;
    private MediaProcessor $processor;
    private CacheManager $cache;

    public function renderGallery(array $media): string
    {
        return $this->security->executeProtected(
            fn() => $this->doRenderGallery($media),
            ['action' => 'render_gallery']
        );
    }

    private function doRenderGallery(array $media): string
    {
        $processedMedia = $this->cache->remember(
            "gallery." . md5(serialize($media)),
            fn() => $this->processor->processMediaItems($media)
        );

        return view('components.gallery', [
            'media' => $processedMedia
        ])->render();
    }

    public function validateMedia(array $media): bool
    {
        foreach ($media as $item) {
            if (!$this->security->validateMediaItem($item)) {
                return false;
            }
        }
        return true;
    }

    public function processThumbnails(UploadedFile $file): array
    {
        return $this->security->executeProtected(
            fn() => $this->processor->createThumbnails($file),
            ['action' => 'process_thumbnails']
        );
    }
}

class UIComponentRegistry
{
    private array $components = [];
    private SecurityManagerInterface $security;
    private ValidationService $validator;

    public function register(string $name, Component $component): void
    {
        $this->security->executeProtected(function() use ($name, $component) {
            $this->validator->validateComponent($component);
            $this->components[$name] = $component;
        }, ['action' => 'register_component']);
    }

    public function render(string $name, array $props = []): string
    {
        return $this->security->executeProtected(
            fn() => $this->components[$name]->render($props),
            ['action' => 'render_component', 'component' => $name]
        );
    }
}
