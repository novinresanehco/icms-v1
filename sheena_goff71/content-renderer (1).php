<?php

namespace App\Core\Display;

class ContentRenderer
{
    private SecurityService $security;
    private MediaProcessor $media;
    private array $allowedTags = [
        'p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'img', 'a', 'strong', 'em', 'blockquote'
    ];

    public function render(string $content, array $options = []): string
    {
        $content = $this->security->sanitize($content, $this->allowedTags);
        $content = $this->processMediaTags($content);
        return $this->wrapContent($content, $options['layout'] ?? 'default');
    }

    private function processMediaTags(string $content): string
    {
        return preg_replace_callback('/\{media:(\d+)\}/', function($matches) {
            return $this->media->render((int)$matches[1]);
        }, $content);
    }

    private function wrapContent(string $content, string $layout): string
    {
        return sprintf(
            '<div class="content-wrapper layout-%s">%s</div>',
            $layout,
            $content
        );
    }
}

class MediaProcessor
{
    private array $sizeMap = [
        'thumb' => [150, 150],
        'medium' => [300, 300],
        'large' => [800, 600]
    ];

    public function render(int $id, string $size = 'medium'): string
    {
        $media = $this->findMedia($id);
        if (!$media) return '';

        [$width, $height] = $this->sizeMap[$size];

        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="%s" loading="lazy" class="media-item">',
            $media->url,
            $width,
            $height,
            htmlspecialchars($media->title)
        );
    }

    private function findMedia(int $id): ?object
    {
        // Implementation bound to media repository
        return null;
    }
}
