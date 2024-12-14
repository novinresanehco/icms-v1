<?php

namespace App\Core\Admin;

class AdminController extends BaseController
{
    public function index()
    {
        return $this->security->executeCriticalOperation(function() {
            $this->authorize('admin.access');
            
            return view('admin.dashboard', [
                'content' => DB::table('content')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get(),
                'categories' => app(CategoryManager::class)->getTree(),
                'media' => DB::table('media')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get()
            ]);
        }, ['action' => 'admin.view']);
    }
}

class TemplateEngine
{
    private SecurityManager $security;
    private CacheManager $cache;
    
    public function __construct(SecurityManager $security, CacheManager $cache)
    {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(function() use ($template, $data) {
            $compiled = $this->compile($template);
            return $this->evaluate($compiled, $data);
        }, ['action' => 'template.render']);
    }

    private function compile(string $template): string
    {
        return $this->cache->remember("template.$template", function() use ($template) {
            $content = file_get_contents($this->getTemplatePath($template));
            return $this->compileDirectives($content);
        });
    }

    private function compileDirectives(string $content): string
    {
        $patterns = [
            '/\{\{ (.+?) \}\}/' => '<?php echo htmlspecialchars($1); ?>',
            '/\{!! (.+?) !!\}/' => '<?php echo $1; ?>',
            '/@if\((.*?)\)/' => '<?php if($1): ?>',
            '/@endif/' => '<?php endif; ?>',
            '/@foreach\((.*?)\)/' => '<?php foreach($1): ?>',
            '/@endforeach/' => '<?php endforeach; ?>'
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $content);
    }

    private function evaluate(string $compiled, array $data): string
    {
        extract($data);
        ob_start();
        eval('?>' . $compiled);
        return ob_get_clean();
    }
}

class AdminTemplate
{
    private TemplateEngine $engine;

    public function __construct(TemplateEngine $engine)
    {
        $this->engine = $engine;
    }

    public function layout(string $content): string
    {
        return $this->engine->render('admin.layout', [
            'content' => $content,
            'menu' => $this->buildMenu(),
            'user' => auth()->user()
        ]);
    }

    private function buildMenu(): array
    {
        return [
            ['title' => 'Dashboard', 'route' => 'admin.dashboard'],
            ['title' => 'Content', 'route' => 'admin.content'],
            ['title' => 'Categories', 'route' => 'admin.categories'],
            ['title' => 'Media', 'route' => 'admin.media']
        ];
    }
}

class Repository 
{
    protected string $table;
    protected SecurityManager $security;
    protected CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function find(int $id)
    {
        return $this->cache->remember("{$this->table}.{$id}", function() use ($id) {
            return DB::table($this->table)->find($id);
        });
    }

    public function create(array $data)
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            $id = DB::table($this->table)->insertGetId($data);
            $this->cache->invalidate(["{$this->table}.list", "{$this->table}.{$id}"]);
            return $id;
        }, ['action' => "{$this->table}.create"]);
    }

    public function update(int $id, array $data)
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            $success = DB::table($this->table)->where('id', $id)->update($data);
            if ($success) {
                $this->cache->invalidate(["{$this->table}.list", "{$this->table}.{$id}"]);
            }
            return $success;
        }, ['action' => "{$this->table}.update"]);
    }

    public function delete(int $id)
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            $success = DB::table($this->table)->delete($id);
            if ($success) {
                $this->cache->invalidate(["{$this->table}.list", "{$this->table}.{$id}"]);
            }
            return $success;
        }, ['action' => "{$this->table}.delete"]);
    }
}

class ContentRepository extends Repository
{
    protected string $table = 'content';

    public function paginate(int $perPage = 15)
    {
        return DB::table($this->table)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}

class CategoryRepository extends Repository
{
    protected string $table = 'categories';

    public function getTree()
    {
        return $this->cache->remember('category.tree', function() {
            return $this->buildTree(DB::table($this->table)->get());
        });
    }

    private function buildTree($items, $parentId = null)
    {
        $branch = [];
        foreach ($items as $item) {
            if ($item->parent_id === $parentId) {
                $children = $this->buildTree($items, $item->id);
                if ($children) {
                    $item->children = $children;
                }
                $branch[] = $item;
            }
        }
        return $branch;
    }
}

// Critical routes
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin', [AdminController::class, 'index'])->name('admin.dashboard');
    Route::resource('admin/content', ContentController::class);
    Route::resource('admin/categories', CategoryController::class);
    Route::resource('admin/media', MediaController::class);
});
