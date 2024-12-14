<?php

namespace App\Core\Template\Contracts;

interface TemplateRepositoryInterface
{
    public function create(array $data): Template;
    public function update(int $id, array $data): Template;
    public function delete(int $id): bool;
    public function find(int $id): ?Template;
    public function findBySlug(string $slug): ?Template;
    public function getActive(): Collection;
}

namespace App\Core\Template\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'content',
        'layout',
        'is_active',
        'metadata'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function regions(): HasMany
    {
        return $this->hasMany(TemplateRegion::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(TemplateVariable::class);
    }
}

class TemplateRegion extends Model
{
    protected $fillable = [
        'name',
        'identifier',
        'template_id',
        'config'
    ];

    protected $casts = [
        'config' => 'array'
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}

class TemplateVariable extends Model
{
    protected $fillable = [
        'name',
        'key',
        'default_value',
        'type',
        'template_id',
        'validation_rules'
    ];

    protected $casts = [
        'validation_rules' => 'array'
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}

namespace App\Core\Template\Services;

use App\Core\Template\Contracts\TemplateRepositoryInterface;
use App\Core\Template\Engines\TemplateEngine;
use App\Core\Template\Events\TemplateCreated;
use App\Core\Template\Events\TemplateUpdated;
use App\Core\Template\Events\TemplateDeleted;
use App\Core\Template\Exceptions\TemplateException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TemplateService
{
    protected TemplateRepositoryInterface $repository;
    protected TemplateEngine $engine;

    public function __construct(
        TemplateRepositoryInterface $repository,
        TemplateEngine $engine
    ) {
        $this->repository = $repository;
        $this->engine = $engine;
    }

    public function create(array $data): Template
    {
        $this->validateTemplate($data);

        DB::beginTransaction();
        try {
            $template = $this->repository->create($data);
            
            if (isset($data['regions'])) {
                $this->createRegions($template, $data['regions']);
            }

            if (isset($data['variables'])) {
                $this->createVariables($template, $data['variables']);
            }

            event(new TemplateCreated($template));
            
            DB::commit();
            $this->clearCache();
            
            return $template;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateException('Failed to create template: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data): Template
    {
        $this->validateTemplate($data, $id);

        DB::beginTransaction();
        try {
            $template = $this->repository->update($id, $data);
            
            if (isset($data['regions'])) {
                $this->updateRegions($template, $data['regions']);
            }

            if (isset($data['variables'])) {
                $this->updateVariables($template, $data['variables']);
            }

            event(new TemplateUpdated($template));
            
            DB::commit();
            $this->clearCache();
            
            return $template;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateException('Failed to update template: ' . $e->getMessage());
        }
    }

    public function render(Template $template, array $data = []): string
    {
        try {
            $this->validateTemplateData($template, $data);
            return $this->engine->render($template, $data);
        } catch (\Exception $e) {
            throw new TemplateException('Failed to render template: ' . $e->getMessage());
        }
    }

    protected function validateTemplate(array $data, ?int $id = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'content' => 'required|string',
            'layout' => 'required|string',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
            'regions' => 'nullable|array',
            'regions.*.name' => 'required|string|max:255',
            'regions.*.identifier' => 'required|string|max:255',
            'regions.*.config' => 'nullable|array',
            'variables' => 'nullable|array',
            'variables.*.name' => 'required|string|max:255',
            'variables.*.key' => 'required|string|max:255',
            'variables.*.type' => 'required|string|in:text,number,boolean,array,object',
            'variables.*.default_value' => 'nullable',
            'variables.*.validation_rules' => 'nullable|array'
        ];

        if ($id === null) {
            $rules['slug'] = 'required|string|unique:templates,slug';
        } else {
            $rules['slug'] = "required|string|unique:templates,slug,{$id}";
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new TemplateException($validator->errors()->first());
        }
    }

    protected function validateTemplateData(Template $template, array $data): void
    {
        $rules = [];
        
        foreach ($template->variables as $variable) {
            if (!empty($variable->validation_rules)) {
                $rules[$variable->key] = $variable->validation_rules;
            }
        }

        if (!empty($rules)) {
            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                throw new TemplateException($validator->errors()->first());
            }
        }
    }

    protected function clearCache(): void
    {
        Cache::tags(['templates'])->flush();
    }

    protected function createRegions(Template $template, array $regions): void
    {
        foreach ($regions as $region) {
            $template->regions()->create($region);
        }
    }

    protected function updateRegions(Template $template, array $regions): void
    {
        $template->regions()->delete();
        $this->createRegions($template, $regions);
    }

    protected function createVariables(Template $template, array $variables): void
    {
        foreach ($variables as $variable) {
            $template->variables()->create($variable);
        }
    }

    protected function updateVariables(Template $template, array $variables): void
    {
        $template->variables()->delete();
        $this->createVariables($template, $variables);
    }
}

namespace App\Core\Template\Engines;

use App\Core\Template\Models\Template;
use App\Core\Template\Exceptions\TemplateException;
use Illuminate\Support\Str;

class TemplateEngine
{
    protected array $filters = [];
    protected array $functions = [];

    public function render(Template $template, array $data = []): string
    {
        try {
            $content = $this->processTemplate($template, $data);
            return $this->applyLayout($template, $content, $data);
        } catch (\Exception $e) {
            throw new TemplateException('Template rendering failed: ' . $e->getMessage());
        }
    }

    public function registerFilter(string $name, callable $callback): void
    {
        $this->filters[$name] = $callback;
    }

    public function registerFunction(string $name, callable $callback): void
    {
        $this->functions[$name] = $callback;
    }

    protected function processTemplate(Template $template, array $data): string
    {
        $content = $template->content;

        // Process variables
        $content = $this->processVariables($content, $data);

        // Process regions
        $content = $this->processRegions($template, $content, $data);

        // Process functions
        $content = $this->processFunctions($content, $data);

        // Process filters
        $content = $this->processFilters($content);

        return $content;
    }

    protected function applyLayout(Template $template, string $content, array $data): string
    {
        if (empty($template->layout)) {
            return $content;
        }

        $layout = $template->layout;
        return str_replace('{{content}}', $content, $layout);
    }

    protected function processVariables(string $content, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function($matches) use ($data) {
            $key = trim($matches[1]);
            return $this->getValue($key, $data);
        }, $content);
    }

    protected function processRegions(Template $template, string $content, array $data): string
    {
        foreach ($template->regions as $region) {
            $regionContent = $data['regions'][$region->identifier] ?? '';
            $content = str_replace(
                "{{region:{$region->identifier}}}",
                $regionContent,
                $content
            );
        }

        return $content;
    }

    protected function processFunctions(string $content, array $data): string
    {
        return preg_replace_callback('/\{\%\s*([^%]+)\s*\%\}/', function($matches) use ($data) {
            $functionCall = trim($matches[1]);
            return $this->executeFunction($functionCall, $data);
        }, $content);
    }

    protected function processFilters(string $content): string
    {
        return preg_replace_callback('/\{\{\s*([^|]+)\|([^}]+)\s*\}\}/', function($matches) {
            $value = trim($matches[1]);
            $filter = trim($matches[2]);
            return $this->applyFilter($value, $filter);
        }, $content);
    }

    protected function getValue(string $key, array $data)
    {
        return data_get($data, $key, '');
    }

    protected function executeFunction(string $functionCall, array $data)
    {
        preg_match('/(\w+)\((.*)\)/', $functionCall, $matches);
        
        if (count($matches) < 3) {
            return '';
        }

        $function = $matches[1];
        $args = array_map('trim', explode(',', $matches[2]));

        if (isset($this->functions[$function])) {
            return call_user_func_array($this->functions[$function], [$args, $data]);
        }

        return '';
    }

    protected function applyFilter(string $value, string $filter)
    {
        if (isset($this->filters[$filter])) {
            return call_user_func($this->filters[$filter], $value);
        }

        return $value;
    }
}
