namespace App\Core\Template;

class TemplateEngine
{
    private SecurityManager $security;
    private RenderEngine $renderer;
    private CacheManager $cache;

    public function render(Template $template, array $data): string
    {
        return DB::transaction(function() use ($template, $data) {
            $this->security->validateTemplate($template);
            $this->security->validateData($data);

            return $this->cache->remember(
                $this->getCacheKey($template, $data),
                function() use ($template, $data) {
                    return $this->renderer->render(
                        $this->loadTemplate($template),
                        $this->processData($data)
                    );
                }
            );
        });
    }

    private function loadTemplate(Template $template): LoadedTemplate
    {
        $content = $template->content;
        $layout = $template->layout;
        $components = $this->loadComponents($template->components);

        return new LoadedTemplate($content, $layout, $components);
    }

    private function processData(array $data): array
    {
        return array_map(
            fn($value) => $this->security->sanitizeValue($value),
            $data
        );
    }

    private function getCacheKey(Template $template, array $data): string
    {
        return "template:{$template->id}:" . md5(serialize($data));
    }
}

class RenderEngine
{
    private array $processors = [];
    private SecurityManager $security;

    public function render(LoadedTemplate $template, array $data): string
    {
        $content = $template->getContent();
        
        foreach ($this->processors as $processor) {
            $content = $processor->process($content, $data);
            $this->security->validateContent($content);
        }

        return $content;
    }
}

class SecurityManager
{
    public function validateTemplate(Template $template): void
    {
        if (!$template->validate()) {
            throw new SecurityException('Invalid template structure');
        }
    }

    public function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidValue($value)) {
                throw new SecurityException("Invalid data for key: {$key}");
            }
        }
    }

    public function validateContent(string $content): void
    {
        if (!$this->isSecureContent($content)) {
            throw new SecurityException('Content failed security validation');
        }
    }

    public function sanitizeValue($value): mixed
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
        }
        return $value;
    }

    private function isValidValue($value): bool
    {
        return !is_resource($value) &&
               (!is_object($value) || $value instanceof Stringable);
    }

    private function isSecureContent(string $content): bool
    {
        return !preg_match('/(?:<script|javascript:|data:)/i', $content);
    }
}

class LoadedTemplate
{
    private string $content;
    private string $layout;
    private array $components;

    public function __construct(string $content, string $layout, array $components)
    {
        $this->content = $content;
        $this->layout = $layout;
        $this->components = $components;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function getComponents(): array
    {
        return $this->components;
    }
}
