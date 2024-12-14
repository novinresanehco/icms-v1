<?php

namespace App\Core\Display;

use App\Core\Security\SecurityManager;
use App\Core\Template\TemplateEngine;
use App\Core\Media\MediaManager;

class DisplayManager
{
    private TemplateEngine $templateEngine;
    private SecurityManager $security;
    private MediaManager $mediaManager;
    
    public function __construct(
        TemplateEngine $templateEngine,
        SecurityManager $security,
        MediaManager $mediaManager
    ) {
        $this->templateEngine = $templateEngine;
        $this->security = $security;
        $this->mediaManager = $mediaManager;
    }

    public function display(string $template, array $content, array $media = []): string
    {
        return $this->security->executeSecure(function() use ($template, $content, $media) {
            // Prepare media content
            $processedMedia = $this->processMedia($media);
            
            // Merge content with processed media
            $displayData = array_merge($content, [
                'media' => $processedMedia,
                'gallery' => $this->renderGallery($processedMedia)
            ]);

            // Render template with prepared data
            return $this->templateEngine->render($template, $displayData);
        });
    }

    protected function processMedia(array $media): array
    {
        $processed = [];
        
        foreach ($media as $item) {
            $processed[] = $this->mediaManager->process($item, [
                'optimize' => true,
                'secure' => true
            ]);
        }

        return $processed;
    }

    protected function renderGallery(array $media): string
    {
        if (empty($media)) {
            return '';
        }

        return $this->templateEngine->render('components.gallery', [
            'items' => $media,
            'config' => $this->getGalleryConfig()
        ]);
    }

    protected function getGalleryConfig(): array
    {
        return [
            'thumbnailSize' => ['width' => 150, 'height' => 150],
            'lightbox' => true,
            'pagination' => true,
            'itemsPerPage' => 12
        ];
    }
}
