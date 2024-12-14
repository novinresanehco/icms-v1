<?php

namespace App\Core\Services;

use App\Core\Repositories\ContentRepository;
use App\Core\Repositories\TemplateRepository;
use App\Core\Repositories\ModuleRepository;
use App\Core\Services\Cache\CacheService;
use App\Models\Content;
use App\Models\Template;
use App\Models\Module;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentService
{
    protected $contentRepository;
    protected $cache;

    public function __construct(
        ContentRepository $contentRepository,
        CacheService $cache
    ) {
        $this->contentRepository = $contentRepository;
        $this->cache = $cache;
    }

    public function createContent(array $data): Content
    {
        $this->validateContent($data);
        return $this->contentRepository->createContent($data);
    }

    public function updateContent(Content $content, array $data): void
    {
        $this->validateContent($data);
        $this->contentRepository->updateContent($content, $data);
    }

    public function publishContent(Content $content): void
    {
        if ($content->status === 'draft') {
            $this->contentRepository->publish($content);
        }
    }

    public function unpublishContent(Content $content): void
    {
        if ($content->status === 'published') {
            $this->contentRepository->unpublish($content);
        }
    }

    protected function validateContent(array $data): void
    {
        validator($data, [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|max:50',
            'status' => 'nullable|string|in:draft,published',
            'meta' => 'nullable|array'
        ])->validate();
    }
}

class TemplateService
{
    protected $templateRepository;
    protected $cache;

    public function __construct(
        TemplateRepository $templateRepository,
        CacheService $cache
    ) {
        $this->templateRepository = $templateRepository;
        $this->cache = $cache;
    }

    public function createTemplate(array $data): Template
    {
        $this->validateTemplate($data);
        return $this->templateRepository->createTemplate($data);
    }

    public function updateTemplate(Template $template, array $data): void
    {
        $this->validateTemplate($data);
        $this->templateRepository->updateTemplate($template, $data);
    }

    public function getActiveTemplates(string $type = null): Collection
    {
        return $this->cache->remember(
            "templates:active:{$type}",
            3600,
            fn() => $this->templateRepository->getActiveTemplates($type)
        );
    }

    protected function validateTemplate(array $data): void
    {
        validator($data, [
            'name' => 'required|string|max:255',
            'path' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'config' => 'nullable|array',
            'is_active' => 'nullable|boolean'
        ])->validate();
    }
}

class ModuleService
{
    protected $moduleRepository;
    protected $cache;

    public function __construct(
        ModuleRepository $moduleRepository,
        CacheService $cache
    ) {
        $this->moduleRepository = $moduleRepository;
        $this->cache = $cache;
    }

    public function registerModule(array $data): Module
    {
        $this->validateModule($data);
        return $this->moduleRepository->registerModule($data);
    }

    public function updateModuleConfig(Module $module, array $config): void
    {
        validator($config, [
            '*' => 'nullable'
        ])->validate();

        $this->moduleRepository->updateModuleConfig($module, $config);
    }

    public function enableModule(Module $module): void
    {
        $this->validateDependencies($module);
        $this->moduleRepository->enableModule($module);
    }

    public function disableModule(Module $module): void
    {
        $this->validateNoDependent($module);
        $this->moduleRepository->disableModule($module);
    }

    public function getEnabledModules(): Collection
    {
        return $this->cache->remember(
            'modules:enabled',
            3600,
            fn() => $this->moduleRepository->getEnabledModules()
        );
    }

    protected function validateModule(array $data): void
    {
        validator($data, [
            'name' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'description' => 'nullable|string',
            'dependencies' => 'nullable|array',
            'config' => 'nullable|array',
            'is_enabled' => 'nullable|boolean'
        ])->validate();
    }

    protected function validateDependencies(Module $module): void
    {
        foreach ($module->dependencies as $dependency) {
            $depModule = $this->moduleRepository->findByName($dependency['name']);
            
            if (!$depModule || !$depModule->is_enabled) {
                throw new \RuntimeException("Required dependency {$dependency['name']} is not enabled");
            }

            if (!version_compare($depModule->version, $dependency['version'], '>=')) {
                throw new \RuntimeException("Dependency {$dependency['name']} version {$dependency['version']} is required");
            }
        }
    }

    protected function validateNoDependent(Module $module): void
    {
        $dependents = $this->moduleRepository->findDependents($module->name);
        
        if ($dependents->isNotEmpty()) {
            $names = $dependents->pluck('name')->join(', ');
            throw new \RuntimeException("Module cannot be disabled because it is required by: {$names}");
        }
    }
}
