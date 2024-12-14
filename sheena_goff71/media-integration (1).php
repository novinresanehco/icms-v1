namespace App\Core\Template;

class MediaIntegrationService
{
    private MediaProcessor $processor;
    private SecurityValidator $security;
    private CacheManager $cache;

    public function processTemplateMedia(Template $template): array
    {
        return $this->cache->remember(
            "template.media.{$template->id}",
            fn() => DB::transaction(fn() => $this->processMedia($template))
        );
    }

    private function processMedia(Template $template): array
    {
        return $template->media
            ->map(fn($media) => $this->processor->process($media))
            ->filter(fn($media) => $this->security->validateMedia($media))
            ->values()
            ->toArray();
    }
}

class MediaProcessor
{
    private array $processors = [];
    private array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

    public function process(Media $media): ProcessedMedia
    {
        if (!in_array($media->mime_type, $this->allowedTypes)) {
            throw new UnsupportedMediaTypeException();
        }

        $processor = $this->processors[$media->type] 
            ?? throw new ProcessorNotFoundException();

        return $processor->process($media);
    }
}

class SecurityValidator
{
    public function validateMedia(ProcessedMedia $media): bool
    {
        return $this->validateMimeType($media->mime_type) &&
               $this->validateDimensions($media->dimensions) &&
               $this->validateFileSize($media->size);
    }

    private function validateMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp']);
    }

    private function validateDimensions(array $dimensions): bool
    {
        return $dimensions['width'] <= 4096 && 
               $dimensions['height'] <= 4096;
    }

    private function validateFileSize(int $size): bool
    {
        return $size <= 10 * 1024 * 1024; // 10MB limit
    }
}
