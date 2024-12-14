namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{View, Cache, Blade};
use Illuminate\Contracts\View\Factory;

class TemplateManager
{
    protected SecurityManager $security;
    protected TemplateRepository $repository;
    protected Factory $view;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        Factory $view
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->view = $view;
    }

    public function render(string $template, array $data = []): string
    {
        $template = $this->repository->findOrFail($template);
        $this->security->validateTemplateAccess($template);

        return $this->view->make($template->path, array_merge(
            $data,
            $this->getDefaultData()
        ))->render();
    }

    public function compile(string $content, array $data = []): string
    {
        $this->security->validateTemplateCompilation($content);
        return Blade::render($content, $data);
    }

    protected function getDefaultData(): array
    {
        return [
            'user' => auth()->user(),
            'settings' => $this->getSettings(),
            'csrf_token' => csrf_token(),
        ];
    }

    protected function getSettings(): array
    {
        return Cache::remember('template_settings', 3600, function() {
            return $this->repository->getSettings();
        });
    }
}

class ComponentManager
{
    protected SecurityManager $security;
    protected ComponentRepository $repository;

    public function create(array $data): Component
    {
        $this->security->validateComponentCreation($data);
        return $this->repository->create($data);
    }

    public function update(int $id, array $data): Component
    {
        $component = $this->repository->findOrFail($id);
        $this->security->validateComponentUpdate($component);
        return $this->repository->update($id, $data);
    }

    public function render(string $name, array $data = []): string
    {
        $component = $this->repository->findByName($name);
        $this->security->validateComponentAccess($component);

        return View::make('components.' . $component->view, array_merge(
            $data,
            ['component' => $component]
        ))->render();
    }
}

class LayoutManager
{
    protected SecurityManager $security;
    protected LayoutRepository $repository;
    protected Factory $view;

    public function render(string $name, array $data = []): string
    {
        $layout = $this->repository->findByName($name);
        $this->security->validateLayoutAccess($layout);

        return $this->view->make($layout->view, array_merge(
            $data,
            ['layout' => $layout]
        ))->render();
    }
}

class ThemeManager
{
    protected SecurityManager $security;
    protected ThemeRepository $repository;
    protected CacheManager $cache;

    public function activate(int $id): Theme
    {
        $theme = $this->repository->findOrFail($id);
        $this->security->validateThemeActivation($theme);

        $this->repository->deactivateAll();
        $this->repository->activate($id);
        $this->cache->invalidateTheme();

        return $theme;
    }

    public function getActive(): Theme
    {
        return Cache::remember('active_theme', 3600, function() {
            return $this->repository->getActive();
        });
    }
}

class AssetManager
{
    protected SecurityManager $security;
    protected string $publicPath;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->publicPath = public_path('themes');
    }

    public function getUrl(string $path): string
    {
        $theme = app(ThemeManager::class)->getActive();
        $fullPath = "{$this->publicPath}/{$theme->id}/$path";

        if (!file_exists($fullPath)) {
            throw new AssetNotFoundException("Asset not found: $path");
        }

        return asset("themes/{$theme->id}/$path");
    }
}

class TemplateException extends \Exception {}
class AssetNotFoundException extends \Exception {}

interface ThemeRepository
{
    public function findOrFail(int $id): Theme;
    public function getActive(): Theme;
    public function activate(int $id): void;
    public function deactivateAll(): void;
}

interface ComponentRepository
{
    public function findOrFail(int $id): Component;
    public function findByName(string $name): Component;
    public function create(array $data): Component;
    public function update(int $id, array $data): Component;
}
