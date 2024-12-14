namespace App\Core\Template;

class TemplateSystem
{
    private ContentRenderer $contentRenderer;
    private MediaManager $mediaManager;
    private SecurityValidator $security;
    private CacheManager $cache;

    public function __construct(
        ContentRenderer $contentRenderer,
        MediaManager $mediaManager,
        SecurityValidator $security,
        CacheManager $cache
    ) {
        $this->contentRenderer = $contentRenderer;
        $this->mediaManager = $mediaManager;
        $this->security = $security;
        $this->cache = $cache;
    }

    public function render(string $templateId, array $data): string
    {
        return DB::transaction(function() use ($templateId, $data) {
            $template = $this->loadTemplate($templateId);
            $this->security->validateTemplate($template);

            $processedData = $this->processData($data);
            
            return $this->cache->remember("template.$templateId", function() use ($template, $processedData) {
                $content = $this->contentRenderer->render($template, $processedData);
                $this->security->validateOutput($content);
                return $content;
            });
        });
    }

    private function processData(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof MediaFile) {
                $data[$key] = $this->mediaManager->process($value);
            }
        }
        
        return $this->security->sanitizeData($data);
    }

    private function loadTemplate(string $id): Template
    {
        $template = Template::findOrFail($id);
        $template->load('sections', 'media', 'styles');
        return $template;
    }
}

class ContentRenderer
{
    private array $renderers = [];

    public function register(string $type, callable $renderer): void
    {
        $this->renderers[$type] = $renderer;
    }

    public function render(Template $template, array $data): string
    {
        $content = $template->content;
        foreach ($this->renderers as $type => $renderer) {
            $content = $renderer($content, $data);
        }
        return $content;
    }
}

class MediaManager
{
    private array $processors = [];
    private SecurityValidator $security;

    public function process(MediaFile $file): ProcessedMedia
    {
        $processor = $this->processors[$file->type] ?? throw new UnsupportedMediaException();
        $processed = $processor($file);
        $this->security->validateMedia($processed);
        return $processed;
    }
}

class SecurityValidator
{
    public function validateTemplate(Template $template): void
    {
        if (!$this->isSecureTemplate($template)) {
            throw new SecurityException('Template failed security validation');
        }
    }

    public function validateOutput(string $content): void
    {
        if (!$this->isSecureContent($content)) {
            throw new SecurityException('Output failed security validation');
        }
    }

    public function validateMedia(ProcessedMedia $media): void
    {
        if (!$this->isSecureMedia($media)) {
            throw new SecurityException('Media failed security validation');
        }
    }

    public function sanitizeData(array $data): array
    {
        return array_map(
            fn($value) => $this->sanitizeValue($value),
            $data
        );
    }

    private function isSecureTemplate(Template $template): bool
    {
        return $template->validateIntegrity() && 
               $template->validatePermissions() && 
               $template->validateStructure();
    }

    private function isSecureContent(string $content): bool
    {
        return !preg_match('/(?:<script|javascript:|data:)/i', $content);
    }

    private function isSecureMedia(ProcessedMedia $media): bool
    {
        return $media->validateChecksum() && 
               $media->validateMimeType() && 
               $media->validateDimensions();
    }

    private function sanitizeValue($value): mixed
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
        }
        return $value;
    }
}
