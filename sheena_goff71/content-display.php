<?php

namespace App\Core\Template\Display;

use App\Core\Security\SecurityManager;
use App\Core\Template\TemplateManager;
use App\Core\Media\MediaManager;

class ContentDisplayManager implements DisplayInterface 
{
    private SecurityManager $security;
    private TemplateManager $template;
    private MediaManager $media;

    public function __construct(
        SecurityManager $security,
        TemplateManager $template,
        MediaManager $media
    ) {
        $this->security = $security;
        $this->template = $template;
        $this->media = $media;
    }

    public function renderContent(Content $content, array $options = []): string 
    {
        return DB::transaction(function() use ($content, $options) {
            // Security validation
            $this->security->validateContentAccess($content);
            
            // Process content with media
            $processedContent = $this->processContentMedia($content);
            
            // Apply content transformations
            $transformedContent = $this->applyContentTransforms($processedContent);
            
            // Render with template
            return $this->template->render('content.display', [
                'content' => $transformedContent,
                'options' => $this->validateOptions($options)
            ]);
        });
    }

    public function renderMediaGallery(Content $content): string 
    {
        $media = $this->media->getContentMedia($content);
        
        return $this->template->render('content.gallery', [
            'media' => $media,
            'content' => $content,
            'layout' => $this->getGalleryLayout($content)
        ]);
    }

    private function processContentMedia(Content $content): ProcessedContent 
    {
        $processor = new ContentProcessor($this->media);
        return $processor->process($content);
    }

    private function applyContentTransforms(ProcessedContent $content): TransformedContent 
    {
        $transformer = new ContentTransformer();
        return $transformer->transform($content);
    }

    private function validateOptions(array $options): array 
    {
        $validator = new DisplayOptionsValidator();
        return $validator->validate($options);
    }

    private function getGalleryLayout(Content $content): array 
    {
        return $this->media->getOptimalGalleryLayout($content->getMedia());
    }
}

class ContentProcessor 
{
    private MediaManager $media;

    public function process(Content $content): ProcessedContent 
    {
        $processed = new ProcessedContent($content);
        
        // Process embedded media
        foreach ($content->getEmbeddedMedia() as $media) {
            $processed->addProcessedMedia(
                $this->media->processMediaItem($media)
            );
        }
        
        return $processed;
    }
}

class ContentTransformer 
{
    public function transform(ProcessedContent $content): TransformedContent 
    {
        $transformed = new TransformedContent($content);
        
        // Apply content transformations
        $transformed->applyHtmlPurifier()
                   ->applyTypography()
                   ->optimizeMedia()
                   ->validateOutput();
        
        return $transformed;
    }
}

class DisplayOptionsValidator 
{
    private const ALLOWED_OPTIONS = [
        'layout', 'theme', 'media_size', 'cache_ttl'
    ];

    public function validate(array $options): array 
    {
        return array_intersect_key(
            $options, 
            array_flip(self::ALLOWED_OPTIONS)
        );
    }
}
