<?php

namespace App\Core\Template\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'content',
        'type',
        'metadata',
        'status'
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function sections()
    {
        return $this->hasMany(TemplateSection::class);
    }

    public function variables()
    {
        return $this->hasMany(TemplateVariable::class);
    }
}

class TemplateSection extends Model
{
    protected $fillable = [
        'template_id',
        'name',
        'content',
        'order',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}

namespace App\Core\Template\Services;

use App\Core\Template\Contracts\TemplateCompilerInterface;
use App\Core\Template\Exceptions\CompilationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;

class BladeTemplateCompiler implements TemplateCompilerInterface
{
    public function compile(string $template, array $data = []): string
    {
        try {
            $hash = md5($template);
            
            return Cache::tags(['templates'])->remember(
                "template.{$hash}",
                now()->addHours(24),
                function () use ($template, $data) {
                    return Blade::compileString($template);
                }
            );
        } catch (\Exception $e) {
            throw new CompilationException("Template compilation failed: {$e->getMessage()}");
        }
    }

    public function render(string $compiled, array $data = []): string
    {
        try {
            return app('blade.compiler')->evaluateString($compiled, $data);
        } catch (\Exception $e) {
            throw new CompilationException("Template rendering failed: {$e->getMessage()}");
        }
    }
}

class TemplateProcessor
{
    private BladeTemplateCompiler $compiler;
    private array $directives = [];

    public function __construct(BladeTemplateCompiler $compiler)
    {
        $this->compiler = $compiler;
    }

    public function registerDirective(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
        Blade::directive($name, $handler);
    }

    public function process(Template $template, array $data = []): string
    {
        $compiled = $this->compiler->compile($template->content);
        return $this->compiler->render($compiled, $data);
    }

    public function processSection(TemplateSection $section, array $data = []): string
    {
        $compiled = $this->compiler->compile($section->content);
        return $this->compiler->render($compiled, $data);
    }
}

namespace App\Core\Template\Services;

use App\Core\Template\Repositories\TemplateRepository;
use App\Core\Template\Exceptions\TemplateException;
use Illuminate\Support\Facades\DB;

class TemplateService
{
    private TemplateRepository $repository;
    private TemplateProcessor $processor;

    public function __construct(
        TemplateRepository $repository,
        TemplateProcessor $processor
    ) {
        $this->repository = $repository;
        $this->processor = $processor;
    }

    public function create(array $data): Template
    {
        try {
            DB::beginTransaction();

            $template = $this->repository->create($data);

            if (isset($data['sections'])) {
                foreach ($data['sections'] as $section) {
                    $template->sections()->create($section);
                }
            }

            DB::commit();
            return $template;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateException("Failed to create template: {$e->getMessage()}");
        }
    }

    public function render(int $templateId, array $data = []): string
    {
        try {
            $template = $this->repository->find($templateId);
            return $this->processor->process($template, $data);
        } catch (\Exception $e) {
            throw new TemplateException("Failed to render template: {$e->getMessage()}");
        }
    }

    public function renderSection(int $sectionId, array $data = []): string
    {
        try {
            $section = $this->repository->findSection($sectionId);
            return $this->processor->processSection($section, $data);
        } catch (\Exception $e) {
            throw new TemplateException("Failed to render section: {$e->getMessage()}");
        }
    }
}

namespace App\Core\Template\Http\Controllers;

use App\Core\Template\Services\TemplateService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    private TemplateService $templateService;

    public function __construct(TemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'content' => 'required|string',
                'type' => 'required|string|max:50',
                'sections' => 'array'
            ]);

            $template = $this->templateService->create($request->all());
            return response()->json($template, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function render(Request $request, int $id): JsonResponse
    {
        try {
            $rendered = $this->templateService->render($id, $request->all());
            return response()->json(['content' => $rendered]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function renderSection(Request $request, int $id): JsonResponse
    {
        try {
            $rendered = $this->templateService->renderSection($id, $request->all());
            return response()->json(['content' => $rendered]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Template\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Services\{BladeTemplateCompiler, TemplateProcessor};

class TemplateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BladeTemplateCompiler::class);
        $this->app->singleton(TemplateProcessor::class);
    }

    public function boot(): void
    {
        $processor = $this->app->make(TemplateProcessor::class);

        $processor->registerDirective('datetime', function ($expression) {
            return "<?php echo date('Y-m-d H:i:s', strtotime($expression)); ?>";
        });

        $processor->registerDirective('currency', function ($expression) {
            return "<?php echo number_format($expression, 2); ?>";
        });
    }
}
