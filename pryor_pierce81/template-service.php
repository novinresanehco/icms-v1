<?php

namespace App\Services;

use App\Interfaces\SecurityServiceInterface;
use App\Models\{Template, Theme};
use Illuminate\Support\Facades\{View, Cache, File, Storage};
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class TemplateService
{
    private SecurityServiceInterface $security;
    private CacheService $cache;
    private string $templatesPath;
    private array $allowedExtensions = ['blade.php', 'twig'];
    private array $defaultVariables = [];

    public function __construct(
        SecurityServiceInterface $security,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->templatesPath = resource_path('views/templates');
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeRender($template, $data),
            ['action' => 'template.render', 'template' => $template]
        );
    }

    private function executeRender(string $template, array $data): string
    {
        $this->validateTemplate($template);
        
        $data = array_merge($this->defaultVariables, $data);
        $this->validateTemplateData($data);

        return $this->cache->remember(
            "template.render.{$template}." . md5(serialize($data)),
            fn() => View::make("templates.$template", $data)->render(),
            3600
        );
    }

    public function createTemplate(array $data): Template
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeCreateTemplate($data),
            ['action' => 'template.create', 'permission' => 'template.manage']
        );
    }

    private function executeCreateTemplate(array $data): Template
    {
        $this->validateTemplateData($data);

        $content = $data['content'];
        unset($data['content']);

        $template = Template::create($data);

        $this->saveTemplateFile($template->name, $content);
        $this->cache->invalidateTag('templates');

        return $template;
    }

    public function updateTemplate(Template $template, array $data): Template
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeUpdateTemplate($template, $data),
            ['action' => 'template.update', 'permission' => 'template.manage']
        );
    }

    private function executeUpdateTemplate(Template $template, array $data): Template
    {
        $this->validateTemplateData($data);

        $content = $data['content'] ?? null;
        unset($data['content']);

        $template->update($data);

        if ($content !== null) {
            $this->saveTemplateFile($template->name, $content);
        }

        $this->cache->invalidateTag('templates');

        return $template->fresh();
    }

    public function deleteTemplate(Template $template): bool
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeDeleteTemplate($template),
            ['action' => 'template.delete', 'permission' => 'template.manage']
        );
    }

    private function executeDeleteTemplate(Template $template): bool
    {
        $this->deleteTemplateFile($template->name);
        $template->delete();
        $this->cache->invalidateTag('templates');
        
        return true;
    }

    public function compileTemplate(string $template): bool
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeCompileTemplate($template),
            ['action' => 'template.compile', 'permission' => 'template.manage']
        );
    }

    private function executeCompileTemplate(string $template): bool
    {
        $this->validateTemplate($template);
        
        try {
            View::make("templates.$template")->render();
            return true;
        } catch (\Throwable $e) {
            throw new TemplateException(
                "Template compilation failed: {$e->getMessage()}"
            );
        }
    }

    public function setTheme(Theme $theme): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->executeSetTheme($theme),
            ['action' => 'theme.set', 'permission' => 'theme.manage']
        );
    }

    private function executeSetTheme(Theme $theme): void
    {
        if (!$theme->isActive()) {
            throw new TemplateException('Theme is not active');
        }

        $this->loadThemeAssets($theme);
        $this->setThemeTemplates($theme);
        $this->cache->invalidateTag('templates');
    }

    private function validateTemplate(string $template): void
    {
        if (empty($template)) {
            throw new TemplateException('Template name cannot be empty');
        }

        $templatePath = $this->getTemplatePath($template);
        
        if (!File::exists($templatePath)) {
            throw new TemplateException('Template not found');
        }

        $extension = File::extension($templatePath);
        if (!in_array($extension, $this->allowedExtensions)) {
            throw new TemplateException('Invalid template extension');
        }
    }

    private function validateTemplateData(array $data): void
    {
        array_walk_recursive($data, function($item) {
            if (is_object($item) && !method_exists($item, '__toString')) {
                throw new TemplateException(
                    'Invalid template data: Objects must implement __toString'
                );
            }
        });
    }

    private function saveTemplateFile(string $name, string $content): void
    {
        $path = $this->getTemplatePath($name);
        
        try {
            File::put($path, $content);
        } catch (\Exception $e) {
            throw new TemplateException(
                "Failed to save template file: {$e->getMessage()}"
            );
        }
    }

    private function deleteTemplateFile(string $name): void
    {
        $path = $this->getTemplatePath($name);
        
        try {
            File::delete($path);
        } catch (\Exception $e) {
            throw new TemplateException(
                "Failed to delete template file: {$e->getMessage()}"
            );
        }
    }

    private function getTemplatePath(string $name): string
    {
        return $this->templatesPath . '/' . $name . '.blade.php';
    }

    private function loadThemeAssets(Theme $theme): void
    {
        $assetsPath = public_path('themes/' . $theme->directory);
        
        if (!File::isDirectory($assetsPath)) {
            throw new TemplateException('Theme assets directory not found');
        }

        // Symlink theme assets to public directory
        $publicLink = public_path('theme');
        if (File::exists($publicLink)) {
            File::delete($publicLink);
        }
        
        File::link($assetsPath, $publicLink);
    }

    private function setThemeTemplates(Theme $theme): void
    {
        $templatesPath = resource_path('views/themes/' . $theme->directory);
        
        if (!File::isDirectory($templatesPath)) {
            throw new TemplateException('Theme templates directory not found');
        }

        View::addNamespace('theme', $templatesPath);
    }
}
