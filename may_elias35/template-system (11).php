// File: app/Core/Template/Manager/TemplateManager.php
<?php

namespace App\Core\Template\Manager;

class TemplateManager
{
    protected TemplateRepository $repository;
    protected TemplateCompiler $compiler;
    protected TemplateCache $cache;
    protected ThemeManager $themeManager;
    protected EventDispatcher $events;

    public function render(string $template, array $data = []): string
    {
        $template = $this->resolveTemplate($template);
        
        if ($cached = $this->cache->get($template, $data)) {
            return $cached;
        }

        $compiled = $this->compiler->compile($template);
        $rendered = $this->renderTemplate($compiled, $data);
        
        $this->cache->put($template, $data, $rendered);
        return $rendered;
    }

    public function registerTheme(Theme $theme): void
    {
        $this->themeManager->register($theme);
        $this->cache->flush();
        $this->events->dispatch(new ThemeRegistered($theme));
    }

    protected function resolveTemplate(string $template): Template
    {
        $activeTheme = $this->themeManager->getActiveTheme();
        return $this->repository->findTemplate($template, $activeTheme);
    }

    protected function renderTemplate(CompiledTemplate $template, array $data): string
    {
        return $template->render($this->prepareData($data));
    }
}

// File: app/Core/Template/Compiler/TemplateCompiler.php
<?php

namespace App\Core\Template\Compiler;

class TemplateCompiler
{
    protected DirectiveProcessor $directiveProcessor;
    protected ExpressionCompiler $expressionCompiler;
    protected CompilerConfig $config;

    public function compile(Template $template): CompiledTemplate
    {
        $content = $template->getContent();
        
        // Process template inheritance
        $content = $this->processInheritance($content);
        
        // Process directives
        $content = $this->directiveProcessor->process($content);
        
        // Compile expressions
        $content = $this->expressionCompiler->compile($content);
        
        return new CompiledTemplate($content);
    }

    protected function processInheritance(string $content): string
    {
        preg_match_all('/@extends\(([^)]+)\)/', $content, $matches);
        
        foreach ($matches[1] as $parent) {
            $content = $this->mergeWithParent($content, trim($parent, "'\""));
        }
        
        return $content;
    }

    protected function mergeWithParent(string $content, string $parent): string
    {
        $parentTemplate = $this->repository->findTemplate($parent);
        $parentContent = $parentTemplate->getContent();
        
        // Extract sections
        $sections = $this->extractSections($content);
        
        // Replace yield directives with section content
        return $this->replaceSections($parentContent, $sections);
    }
}

// File: app/Core/Template/Theme/ThemeManager.php
<?php

namespace App\Core\Template\Theme;

class ThemeManager
{
    protected ThemeRepository $repository;
    protected ThemeLoader $loader;
    protected ThemeConfig $config;
    protected ?Theme $activeTheme = null;

    public function register(Theme $theme): void
    {
        $this->validateTheme($theme);
        $this->repository->save($theme);
        $this->loader->loadAssets($theme);
    }

    public function setActiveTheme(string $themeId): void
    {
        $theme = $this->repository->find($themeId);
        
        if (!$theme) {
            throw new ThemeException("Theme not found: {$themeId}");
        }
        
        $this->activeTheme = $theme;
        $this->config->setActiveTheme($themeId);
    }

    public function getActiveTheme(): Theme
    {
        if (!$this->activeTheme) {
            $themeId = $this->config->getActiveTheme();
            $this->activeTheme = $this->repository->find($themeId);
        }
        
        return $this->activeTheme;
    }

    protected function validateTheme(Theme $theme): void
    {
        if (!$theme->hasValidStructure()) {
            throw new ThemeException("Invalid theme structure");
        }

        if ($this->repository->exists($theme->getId())) {
            throw new ThemeException("Theme already exists");
        }
    }
}

// File: app/Core/Template/Component/ComponentRegistry.php
<?php

namespace App\Core\Template\Component;

class ComponentRegistry
{
    protected array $components = [];
    protected ComponentValidator $validator;
    protected ComponentCompiler $compiler;

    public function register(string $name, Component $component): void
    {
        $this->validator->validate($component);
        $this->components[$name] = $component;
    }

    public function render(string $name, array $props = []): string
    {
        if (!isset($this->components[$name])) {
            throw new ComponentException("Component not found: {$name}");
        }

        $component = $this->components[$name];
        $compiledComponent = $this->compiler->compile($component);
        
        return $compiledComponent->render($props);
    }

    public function getAllComponents(): array
    {
        return $this->components;
    }

    public function exists(string $name): bool
    {
        return isset($this->components[$name]);
    }
}
