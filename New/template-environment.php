<?php

namespace App\Core\Template\Environment;

use App\Core\Template\Compilation\{
    EnhancedTemplateCompiler,
    TemplateValidator,
    TemplateCacheManager
};
use App\Core\Template\Components\ComponentLoader;
use App\Core\Template\Lifecycle\LifecycleManager;
use App\Core\Security\SecurityManagerInterface;

class TemplateEnvironment
{
    private EnhancedTemplateCompiler $compiler;
    private ComponentLoader $componentLoader;
    private LifecycleManager $lifecycleManager;
    private array $globals = [];
    private array $config;

    public function __construct(
        EnhancedTemplateCompiler $compiler,
        ComponentLoader $componentLoader,
        LifecycleManager $lifecycleManager,
        array $config = []
    ) {
        $this->compiler = $compiler;
        $this->componentLoader = $componentLoader;
        $this->lifecycleManager = $lifecycleManager;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        $mergedData = array_merge($this->globals, $data);
        
        $compiledTemplate = $this->compiler->compile($template);
        return $this->renderTemplate($compiledTemplate, $mergedData);
    }

    public function addGlobal(string $name, $value): void
    {
        $this->globals[$name] = $value;
    }

    public function extend(string $name, callable $extension): void
    {
        $this->compiler->addDirective($name, $extension);
    }

    private function renderTemplate(CompiledTemplate $template, array $data): string
    {
        extract($data, EXTR_SKIP);
        
        ob_start();
        include $template->getPath();
        return ob_get_clean();
    }
}

class TemplateLoader
{
    private string $templatePath;
    private array $paths = [];
    private array $cache = [];

    public function __construct(string $templatePath)
    {
        $this->templatePath = $templatePath;
    }

    public function addPath(string $namespace, string $path): void
    {
        $this->paths[$namespace] = rtrim($path, '/');
    }

    public function load(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->findTemplate($name);
        $this->cache[$name] = $path;

        return $path;
    }

    private function findTemplate(string $name): string
    {
        if (strpos($name, ':') !== false) {
            [$namespace, $name] = explode(':', $name, 2);
            
            if (!isset($this->paths[$namespace])) {
                throw new \RuntimeException("Template namespace not found: {$namespace}");
            }
            
            $path = $this->paths[$namespace] . '/' . $name;
        } else {
            $path = $this->templatePath . '/' . $name;
        }

        if (!file_exists($path)) {
            throw new \RuntimeException("Template not found: {$name}");
        }

        return $path;
    }
}

class TemplateEnvironmentFactory
{
    private SecurityManagerInterface $security;
    private \PDO $db;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        \PDO $db,
        array $config
    ) {
        $this->security = $security;
        $this->db = $db;
        $this->config = $config;
    }

    public function create(): TemplateEnvironment
    {
        $compiler = $this->createCompiler();
        $componentLoader = $this->createComponentLoader();
        $lifecycleManager = $this->createLifecycleManager();

        return new TemplateEnvironment(
            $compiler,
            $componentLoader,
            $lifecycleManager,
            $this->config
        );
    }

    private function createCompiler(): EnhancedTemplateCompiler
    {
        $factory = new TemplateEngineFactory(
            $this->security,
            $this->db,
            $this->config
        );
        
        return $factory->create();
    }

    private function createComponentLoader(): ComponentLoader
    {
        return new ComponentLoader($this->config['components_path']);
    }

    private function createLifecycleManager(): LifecycleManager
    {
        $monitor = new TemplateMonitoringService(
            $this->db,
            $this->config['environment']
        );
        
        return new LifecycleManager($monitor);
    }
}