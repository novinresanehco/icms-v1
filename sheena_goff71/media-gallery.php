namespace App\Core\UI\Media;

class MediaGalleryManager implements MediaGalleryInterface 
{
    private MediaProcessor $processor;
    private CacheManager $cache;
    private SecurityManager $security;

    public function __construct(
        MediaProcessor $processor,
        CacheManager $cache,
        SecurityManager $security
    ) {
        $this->processor = $processor;
        $this->cache = $cache;
        $this->security = $security;
    }

    public function renderGallery(array $media, array $options = []): string 
    {
        $cacheKey = $this->generateCacheKey($media, $options);

        return $this->cache->remember($cacheKey, function() use ($media, $options) {
            $processedMedia = $this->processMediaItems($media);
            
            return $this->renderGalleryTemplate(
                $processedMedia,
                $this->validateOptions($options)
            );
        });
    }

    private function processMediaItems(array $media): array 
    {
        return array_map(function($item) {
            return $this->processor->process($item, [
                'optimize' => true,
                'secure' => true,
                'responsive' => true
            ]);
        }, $media);
    }

    private function renderGalleryTemplate(array $media, array $options): string 
    {
        return $this->security->renderSecure(
            'media/gallery',
            [
                'items' => $media,
                'options' => $options,
                'layout' => $options['layout'] ?? 'grid'
            ]
        );
    }

    private function validateOptions(array $options): array 
    {
        $defaults = [
            'layout' => 'grid',
            'perPage' => 12,
            'maxWidth' => 1200,
            'lazyLoad' => true
        ];

        return array_merge($defaults, $options);
    }

    private function generateCacheKey(array $media, array $options): string 
    {
        return hash('sha256', serialize([
            'media' => array_column($media, 'id'),
            'options' => $options,
            'version' => '1.0'
        ]));
    }
}
