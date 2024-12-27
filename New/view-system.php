<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;

class ViewManager
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private TemplateEngine $engine;
    private ThemeManager $themes;
    private array $shared = [];

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        TemplateEngine $engine,
        ThemeManager $themes
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->engine = $engine;
        $this->themes = $themes;
    }

    public function render(string $view, array $data = []): string
    {
        $theme = $this->themes->getActive();
        $template = $theme->getPath() . '/' . $view;

        return $this->security->executeInContext(function() use ($template, $data) {
            return $this->engine->render($template, array_merge(
                $this->shared,
                $data
            ));
        });
    }

    public function share(string $key, $value): void
    {
        $this->security->validateData($key, $value);
        $this->shared[$key] = $value;
    }

    public function exists(string $view): bool
    {
        $theme = $this->themes->getActive();
        $template = $theme->getPath() . '/' . $view;

        return $this->security->validateResource($template) && 
               file_exists($template);
    }

    public function first(array $views, array $data = []): string
    {
        foreach ($views as $view) {
            if ($this->exists($view)) {
                return $this->render($view, $data);
            }
        }

        throw new ViewException('None of the views exist');
    }
}

class ViewCompiler
{
    private SecurityManagerInterface $security;
    private string $compilePath;

    public function __construct(
        SecurityManagerInterface $security,
        string $compilePath
    ) {
        $this->security = $security;
        $this->compilePath = $compilePath;
    }

    public function compile(string $view): CompiledTemplate
    {
        $content = $this->security->executeInContext(function() use ($view) {
            if (!file_exists($view)) {
                throw new ViewException("View not found: {$view}");
            }

            return file_get_contents($view);
        });

        $compiled = $this->compileString($content);
        $path = $this->getCompiledPath($view);

        file_put_contents($path, $compiled);

        return new CompiledTemplate($path);
    }

    private function compileString(string $content): string
    {
        // Remove potential PHP code
        $content = preg_replace('/<\?php[\s\S]*?\?>/i', '', $content);

        // Compile directives
        $content = $this->compileDirectives($content);

        // Escape output
        $content = $this->escapeOutput($content);

        return $content;
    }

    private function compileDirectives(string $content): string
    {
        // Implement template directive compilation
        return $content;
    }

    private function escapeOutput(string $content): string
    {
        return htmlspecialchars($content, ENT_QUOTES, 'UTF-8', true);
    }

    private function getCompiledPath(string $view): string
    {
        return $this->compilePath . '/' . sha1($view) . '.php';
    }
}

class ViewFactory
{
    private SecurityManagerInterface $security;
    private ViewManager $views;
    private array $extensions = [];

    public function __construct(
        SecurityManagerInterface $security,
        ViewManager $views
    ) {
        $this->security = $security;
        $this->views = $views;
    }

    public function make(string $view, array $data = []): string
    {
        $this->security->validateInput(['view' => $view, 'data' => $data]);

        return $this->views->render($view, $data);
    }

    public function exists(string $view): bool
    {
        return $this->views->exists($view);
    }

    public function share(string $key, $value): void
    {
        $this->views->share($key, $value);
    }

    public function composer(string $view, callable $callback): void
    {
        $this->extensions[$view][] = $callback;
    }

    public function creator(string $view, callable $callback): void
    {
        $this->extensions[$view][] = $callback;
    }
}