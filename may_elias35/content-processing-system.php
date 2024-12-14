// File: app/Core/Content/Processing/ContentProcessor.php
<?php

namespace App\Core\Content\Processing;

class ContentProcessor
{
    protected array $processors = [];
    protected ProcessingConfig $config;
    protected ProcessingMetrics $metrics;

    public function process(Content $content): ProcessedContent
    {
        $context = $this->createContext($content);
        $processedContent = $content;

        foreach ($this->getProcessors($content) as $processor) {
            try {
                $processedContent = $processor->process($processedContent, $context);
                $this->metrics->recordSuccess($processor, $content);
            } catch (ProcessingException $e) {
                $this->metrics->recordFailure($processor, $content, $e);
                if ($processor->isRequired()) {
                    throw $e;
                }
            }
        }

        return new ProcessedContent($processedContent, $context);
    }

    protected function getProcessors(Content $content): array
    {
        return array_filter($this->processors, function($processor) use ($content) {
            return $processor->supports($content->type);
        });
    }
}

// File: app/Core/Content/Processing/Processors/MediaProcessor.php 
<?php

namespace App\Core\Content\Processing\Processors;

class MediaProcessor implements ContentProcessorInterface
{
    protected MediaRepository $mediaRepo;
    protected ImageOptimizer $imageOptimizer;
    protected array $supportedTypes = ['post', 'article', 'page'];

    public function process(Content $content, ProcessingContext $context): Content 
    {
        $mediaIds = $this->extractMediaIds($content);
        $media = $this->mediaRepo->findMany($mediaIds);

        foreach ($media as $item) {
            if ($item->type === 'image') {
                $this->processImage($item);
            }
        }

        $content->processed_media = $media;
        return $content;
    }

    public function supports(string $type): bool
    {
        return in_array($type, $this->supportedTypes);
    }

    public function isRequired(): bool
    {
        return true;
    }
}
