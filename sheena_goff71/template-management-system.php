<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\{View, Blade};
use League\CommonMark\CommonMarkConverter;

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private Filesystem $storage;
    private CommonMarkConverter $markdown;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        Filesystem $storage,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->markdown = new CommonMarkConverter(['html_input' => 'escape']);
        $this->config = $config;
    }

    public function renderContent(Content $content, array $params = []): string 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performRender($content, $params),
            ['action' => 'render_content', 'content_id' => $content->id]
        );
    }

    private function performRender(Content $content, array $params): string
    {
        // Load template
        $template = $this->loadTemplate($content->template ?? 'default');

        // Process content body
        $processedContent = $this->processContent($content->content, $content->format);

        // Prepare view data
        $data = array_merge(
            $params,
            [
                'content' => $content,
                'processed_content' => $processedContent,
                'meta' => $content->meta,
                'categories' => $content->categories,
                'media' => $content->media
            ]
        );

        // Render with security checks
        return $this->secureRender($template, $data);
    }

    private function loadTemplate(string $name): Template
    {
        return $this->cache->remember(
            "template:$name",
            3600,
            fn() => $this->loadTemplateFromStorage($name)
        );
    }

    private function loadTemplateFromStorage(string $name): Template
    {
        $path = "templates/$name";
        
        if (!$this->storage->exists("$path/template.php")) {
            throw new TemplateNotFoundException("Template '$name' not found");
        }

        // Load template configuration
        $config = json_decode(
            $this->storage->get("$path/config.json"),
            true
        );

        // Validate template structure and security
        $this->validateTemplate($path, $config);

        return new Template(
            name: $name,
            path: $path,
            config: $config,
            content: $this->storage->get("$path/template.php")
        );
    }

    private function validateTemplate(string $path, array $config): void
    {
        // Verify required files exist
        $requiredFiles = ['template.php', 'config.json', 'assets/style.css'];
        foreach ($requiredFiles as $file) {
            if (!$this->storage->exists("$path/$file")) {
                throw new TemplateValidationException("Missing required file: $file");
            }
        }

        // Validate template configuration
        $requiredConfig = ['version', 'supported_content_types', 'regions'];
        foreach ($requiredConfig as $key) {
            if (!isset($config[$key])) {
                throw new TemplateValidationException("Missing required config: $key");
            }
        }

        // Scan for security vulnerabilities
        $this->scanTemplateForVulnerabilities($path);
    }

    private function processContent(string $content, string $format): string
    {
        return match($format) {
            'markdown' => $this->markdown->convert($content)->getContent(),
            'html' => $this->sanitizeHtml($content),
            default => htmlspecialchars($content, ENT_QUOTES, 'UTF-8')
        };
    }

    private function sanitizeHtml(string $html): string
    {
        return $this->security->sanitizeHtml($html, [
            'allowed_tags' => $this->config['allowed_html_tags'],
            'allowed_attributes' => $this->config['allowed_html_attributes']
        ]);
    }

    private function secureRender(Template $template, array $data): string
    {
        try {
            // Create isolated rendering environment
            $renderer = new SecureTemplateRenderer(
                $template,
                $this->config['template_functions']
            );

            // Render with timeout and memory limits
            return $renderer->render($data);

        } catch (\Throwable $e) {
            // Fallback to error template
            return $this->renderError($e);
        }
    }

    private function scanTemplateForVulnerabilities(string $path): void
    {
        $scanner = new TemplateSecurity($this->config['security_rules']);
        
        // Scan PHP files
        foreach ($this->storage->files($path) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $content = $this->storage->get($file);
                $scanner->scanCode($content);
            }
        }

        // Scan assets
        $scanner->scanAssets("$path/assets");
    }

    private function renderError(\Throwable $e): string
    {
        return View::make('errors.template', [
            'message' => $this->config['debug'] ? $e->getMessage() : 'Template error'
        ])->render();
    }

    public function compileAssets(Template $template): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->performAssetCompilation($template),
            ['action' => 'compile_assets', 'template' => $template->name]
        );
    }

    private function performAssetCompilation(Template $template): void
    {
        $assetPath = "templates/{$template->name}/assets";
        
        // Compile and minify CSS
        $css = $this->compileCss($assetPath);
        $this->storage->put(
            "public/templates/{$template->name}/style.min.css",
            $css
        );

        // Compile and minify JS
        $js = $this->compileJs($assetPath);
        $this->storage->put(
            "public/templates/{$template->name}/script.min.js",
            $js
        );

        // Process and optimize images
        $this->processImages($assetPath);

        // Clear asset caches
        $this->cache->tags(['template_assets'])->flush();
    }

    private function compileCss(string $path): string
    {
        // Implement CSS compilation with security checks
        // Return minified CSS
    }

    private function compileJs(string $path): string
    {
        // Implement JS compilation with security checks
        // Return minified JS
    }

    private function processImages(string $path): void
    {
        // Implement secure image processing
    }
}
