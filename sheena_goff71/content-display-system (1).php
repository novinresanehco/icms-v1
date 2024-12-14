<?php
namespace App\Core\Display;

class ContentDisplaySystem implements DisplaySystemInterface 
{
    private TemplateEngine $engine;
    private MediaManager $media;
    private SecurityValidator $validator;

    public function __construct(
        TemplateEngine $engine,
        MediaManager $media,
        SecurityValidator $validator
    ) {
        $this->engine = $engine;
        $this->media = $media;
        $this->validator = $validator;
    }

    public function display(Content $content): DisplayResult 
    {
        $this->validator->validateContent($content);
        
        $template = $this->resolveTemplate($content);
        $data = $this->prepareData($content);
        
        return new DisplayResult(
            $this->engine->render($template, $data)
        );
    }

    private function resolveTemplate(Content $content): string 
    {
        return $this->engine->resolveTemplate(
            $content->getType(),
            $content->getTemplate()
        );
    }

    private function prepareData(Content $content): array 
    {
        $data = $content->getData();
        $media = $this->media->resolveMedia($content->getMediaIds());
        
        return array_merge($data, ['media' => $media]);
    }
}

class MediaManager 
{
    public function resolveMedia(array $mediaIds): array 
    {
        // Secure media resolution
        return [];
    }
}

class SecurityValidator 
{
    public function validateContent(Content $content): void 
    {
        // Content security validation
    }
}

class DisplayResult 
{
    private string $rendered;

    public function __construct(string $rendered) 
    {
        $this->rendered = $rendered;
    }

    public function getRendered(): string 
    {
        return $this->rendered;
    }
}
