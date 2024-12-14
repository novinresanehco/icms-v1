<?php

namespace App\Core\Repositories;

use App\Models\Template;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class TemplateRepository extends AdvancedRepository
{
    protected $model = Template::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function findByTheme(string $theme): Collection
    {
        return $this->executeQuery(function() use ($theme) {
            return $this->cache->remember("templates.theme.{$theme}", function() use ($theme) {
                return $this->model
                    ->where('theme', $theme)
                    ->orderBy('name')
                    ->get();
            });
        });
    }

    public function getActive(): Template
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('template.active', function() {
                return $this->model
                    ->where('active', true)
                    ->firstOrFail();
            });
        });
    }

    public function setActive(Template $template): void
    {
        $this->executeTransaction(function() use ($template) {
            $this->model->where('active', true)->update(['active' => false]);
            $template->update(['active' => true]);
            $this->cache->forget('template.active');
        });
    }

    public function compile(Template $template): string
    {
        return $this->executeQuery(function() use ($template) {
            return $this->cache->remember("template.compiled.{$template->id}", function() use ($template) {
                return view()->file($template->path)->render();
            });
        });
    }
}
