<?php

namespace App\Core\Template;

class TemplateManager
{
    protected CacheManager $cache;
    protected SecurityManager $security;
    protected ThemeRepository $themes;

    public function render(string $template, array $data = []): string 
    {
        return $this->cache->remember("template.$template", function() use ($template, $data) {
            return $this->security->sanitizeOutput(
                $this->compile($template, $data)
            );
        });
    }

    protected function compile(string $template, array $data): string 
    {
        $theme = $this->themes->getActive();
        $path = $theme->path . '/' . $template;

        if (!file_exists($path)) {
            throw new TemplateNotFoundException("Template not found: $template");
        }

        ob_start();
        extract($data);
        include $path;
        return ob_get_clean();
    }
}

class Theme extends Model 
{
    protected $fillable = [
        'name', 'path', 'active'
    ];

    public function layouts()
    {
        return $this->hasMany(Layout::class);
    }

    public function components()
    {
        return $this->hasMany(Component::class);
    }
}

class Layout extends Model
{
    protected $fillable = [
        'name', 'content', 'theme_id'
    ];

    public function theme()
    {
        return $this->belongsTo(Theme::class);
    }

    public function sections()
    {
        return $this->hasMany(Section::class);
    }
}

class Component extends Model
{
    protected $fillable = [
        'name', 'content', 'theme_id'
    ];

    public function render(array $data = []): string
    {
        return app(TemplateManager::class)->render(
            "components/{$this->name}",
            $data
        );
    }
}

class ThemeRepository
{
    protected Theme $model;
    protected CacheManager $cache;

    public function getActive(): Theme
    {
        return $this->cache->remember('active_theme', function() {
            return $this->model->where('active', true)->firstOrFail();
        });
    }

    public function setActive(int $id): Theme
    {
        return DB::transaction(function() use ($id) {
            $this->model->where('active', true)
                ->update(['active' => false]);

            $theme = $this->model->findOrFail($id);
            $theme->update(['active' => true]);

            $this->cache->forget('active_theme');
            return $theme;
        });
    }
}

class TemplateCache implements CacheManager
{
    public function remember(string $key, callable $callback): mixed
    {
        $value = Cache::get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        Cache::put($key, $value, now()->addHours(24));

        return $value;
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }
}

class SecurityManager 
{
    public function sanitizeOutput(string $content): string
    {
        return htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

trait HasComponents
{
    protected array $components = [];

    public function component(string $name, array $data = []): string
    {
        if (!isset($this->components[$name])) {
            $this->components[$name] = Component::where('name', $name)
                ->where('theme_id', $this->theme_id)
                ->firstOrFail();
        }

        return $this->components[$name]->render($data);
    }
}
