<?php

namespace App\Core\Template\Layout;

class LayoutManager
{
    protected TemplateManager $templateManager;
    protected array $sections = [];
    protected ?string $currentSection = null;
    protected array $layouts = [];
    
    /**
     * Start a new template section
     */
    public function startSection(string $name): void
    {
        if ($this->currentSection) {
            throw new LayoutException('Cannot nest sections');
        }
        
        $this->currentSection = $name;
        ob_start();
    }
    
    /**
     * End the current section
     */
    public function endSection(): void
    {
        if (!$this->currentSection) {
            throw new LayoutException('No section started');
        }
        
        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }
    
    /**
     * Extend a layout
     */
    public function extend(string $layout): void
    {
        $this->layouts[] = $layout;
    }
    
    /**
     * Render a layout with sections
     */
    public function render(string $view, array $data = []): string
    {
        $content = $this->templateManager->render($view, $data);
        
        foreach (array_reverse($this->layouts) as $layout) {
            $content = $this->templateManager->render($layout, array_merge(
                $data,
                ['content' => $content],
                $this->sections
            ));
        }
        
        return $content;
    }
}

namespace App\Core\Template\Composers;

class ViewComposerManager
{
    protected array $composers = [];
    protected array $sharedData = [];
    
    /**
     * Register a view composer
     */
    public function composer(string $view, callable $callback): void
    {
        $this->composers[$view][] = $callback;
    }
    
    /**
     * Share data with all views
     */
    public function share(string $key, $value): void
    {
        $this->sharedData[$key] = $value;
    }
    
    /**
     * Compose a view with registered composers
     */
    public function compose(string $view, array $data): array
    {
        $data = array_merge($this->sharedData, $data);
        
        foreach ($this->getComposers($view) as $composer) {
            $data = array_merge($data, call_user_func($composer, $data));
        }
        
        return $data;
    }
    
    /**
     * Get all composers for a view
     */
    protected function getComposers(string $view): array
    {
        $composers = [];
        
        foreach ($this->composers as $pattern => $callbacks) {
            if ($this->viewMatchesPattern($view, $pattern)) {
                $composers = array_merge($composers, $callbacks);
            }
        }
        
        return $composers;
    }
}

namespace App\Core\Template\Components;

class LayoutComponent
{
    protected string $name;
    protected array $slots = [];
    protected array $attributes = [];
    
    /**
     * Define a named slot
     */
    public function slot(string $name, callable $callback): void
    {
        ob_start();
        $callback();
        $this->slots[$name] = ob_get_clean();
    }
    
    /**
     * Render the component with slots
     */
    public function render(): string
    {
        return view("components.{$this->name}", [
            'slots' => $this->slots,
            'attributes' => $this->attributes
        ])->render();
    }
}

namespace App\Core\Template\Directives;

class LayoutDirectives
{
    protected LayoutManager $layoutManager;
    
    /**
     * Register all layout directives
     */
    public function register(): void
    {
        $this->registerExtendDirective();
        $this->registerSectionDirectives();
        $this->registerIncludeDirective();
        $this->registerStackDirectives();
    }
    
    /**
     * Register the @extend directive
     */
    protected function registerExtendDirective(): void
    {
        $this->templateManager->directive('extends', function($expression) {
            return "<?php \$this->layoutManager->extend({$expression}); ?>";
        });
    }
    
    /**
     * Register section directives
     */
    protected function registerSectionDirectives(): void
    {
        $this->templateManager->directive('section', function($expression) {
            return "<?php \$this->layoutManager->startSection({$expression}); ?>";
        });
        
        $this->templateManager->directive('endsection', function() {
            return "<?php \$this->layoutManager->endSection(); ?>";
        });
    }
}

namespace App\Core\Template\Assets;

class AssetManager
{
    protected Filesystem $filesystem;
    protected string $publicPath;
    protected array $manifest;
    
    /**
     * Get asset URL with versioning
     */
    public function asset(string $path): string
    {
        $manifestPath = $this->getManifestPath($path);
        
        return asset($manifestPath ?? $path);
    }
    
    /**
     * Get manifest path for asset
     */
    protected function getManifestPath(string $path): ?string
    {
        if (!$this->manifest) {
            $this->loadManifest();
        }
        
        return $this->manifest[$path] ?? null;
    }
    
    /**
     * Load asset manifest
     */
    protected function loadManifest(): void
    {
        $path = public_path('mix-manifest.json');
        
        if ($this->filesystem->exists($path)) {
            $this->manifest = json_decode($this->filesystem->get($path), true);
        } else {
            $this->manifest = [];
        }
    }
}
