namespace App\Core\Media;

class MediaGalleryManager implements GalleryManagerInterface 
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected FileManager $files;
    protected CacheManager $cache;

    public function renderGallery(array $media, array $options = []): string 
    {
        try {
            // Validate media items
            $validatedMedia = $this->validateMedia($media);
            
            // Process media for display
            $processedMedia = $this->processMediaItems($validatedMedia);
            
            // Apply security filters
            $secureMedia = $this->applySecurityFilters($processedMedia);
            
            // Generate gallery layout
            return $this->generateGalleryHtml($secureMedia, $options);

        } catch (\Exception $e) {
            throw new GalleryException(
                'Gallery generation failed: ' . $e->getMessage(), 
                previous: $e
            );
        }
    }

    protected function validateMedia(array $media): array 
    {
        return array_filter($media, function($item) {
            return $this->validator->validateMediaItem($item);
        });
    }

    protected function processMediaItems(array $media): array 
    {
        return array_map(function($item) {
            return $this->processMediaItem($item);
        }, $media);
    }

    protected function processMediaItem(MediaItem $item): array 
    {
        return [
            'url' => $this->security->secureUrl($item->getUrl()),
            'thumbnail' => $this->generateThumbnail($item),
            'meta' => $this->sanitizeMetadata($item->getMeta()),
            'permissions' => $this->validatePermissions($item)
        ];
    }

    protected function generateThumbnail(MediaItem $item): string 
    {
        return $this->files->generateSecureThumbnail(
            $item,
            $this->getThumbConfig()
        );
    }

    protected function applySecurityFilters(array $media): array 
    {
        return array_map(function($item) {
            return $this->security->filterMediaItem($item);
        }, $media);
    }

    protected function sanitizeMetadata(array $meta): array 
    {
        return array_map(function($value) {
            return $this->security->sanitizeValue($value);
        }, $meta);
    }

    protected function validatePermissions(MediaItem $item): array 
    {
        return $this->security->validateMediaPermissions($item);
    }

    protected function generateGalleryHtml(array $media, array $options): string 
    {
        $template = $this->loadGalleryTemplate($options);
        return $this->renderTemplate($template, [
            'media' => $media,
            'options' => $this->sanitizeOptions($options)
        ]);
    }

    protected function loadGalleryTemplate(array $options): string 
    {
        $type = $options['type'] ?? 'default';
        return $this->cache->remember(
            "gallery.template.{$type}",
            3600,
            fn() => $this->files->loadTemplate("gallery.{$type}")
        );
    }
}
