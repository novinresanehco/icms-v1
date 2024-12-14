namespace App\Core\Template;

class AdvancedTemplateEngine 
{
    private SecurityManager $security;
    private RendererFactory $rendererFactory;
    private CacheManager $cache;
    private MediaProcessor $media;

    public function process(TemplateRequest $request): TemplateResponse 
    {
        return DB::transaction(function() use ($request) {
            $this->security->validateRequest($request);
            
            return $this->cache->remember($request->getCacheKey(), function() use ($request) {
                $renderer = $this->rendererFactory->create($request->getType());
                $template = $this->loadTemplate($request);
                
                $processed = $renderer->render($template, $request->getData());
                $this->security->validateOutput($processed);
                
                return new TemplateResponse($processed);
            });
        });
    }

    private function loadTemplate(TemplateRequest $request): Template 
    {
        $template = Template::findOrFail($request->getTemplateId());
        
        if ($template->hasMedia()) {
            $template->media = $this->media->process($template->media);
        }
        
        return $template;
    }
}

class RendererFactory 
{
    private array $renderers = [];

    public function register(string $type, BaseRenderer $renderer): void 
    {
        $this->renderers[$type] = $renderer;
    }

    public function create(string $type): BaseRenderer 
    {
        return $this->renderers[$type] ?? throw new UnsupportedTemplateException();
    }
}

abstract class BaseRenderer 
{
    protected SecurityManager $security;
    protected MediaProcessor $media;

    abstract public function render(Template $template, array $data): string;

    protected function processSection(string $content, array $data): string 
    {
        $content = $this->security->sanitize($content);
        return $this->interpolate($content, $data);
    }

    protected function interpolate(string $content, array $data): string 
    {
        foreach ($data as $key => $value) {
            $content = str_replace("{{{$key}}}", $value, $content);
        }
        return $content;
    }
}

class MediaProcessor 
{
    private array $processors;
    private SecurityManager $security;

    public function process(Collection $media): Collection 
    {
        return $media->map(function($item) {
            $processor = $this->processors[$item->type];
            $processed = $processor->process($item);
            $this->security->validateMedia($processed);
            return $processed;
        });
    }
}
