<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View};
use App\Core\Security\SecurityManager;

class TemplateManager
{
    private SecurityManager $security;
    private TemplateCompiler $compiler;
    private array $defaultVars = [];
    
    public function __construct(SecurityManager $security, TemplateCompiler $compiler)
    {
        $this->security = $security;
        $this->compiler = $compiler;
    }

    public function render(string $template, array $data = []): string
    {
        return Cache::remember(
            $this->getCacheKey($template, $data),
            3600,
            fn() => $this->renderTemplate($template, $data)
        );
    }

    public function compile(string $template): void
    {
        DB::beginTransaction();
        try {
            $compiled = $this->compiler->compile($template);
            $this->validateCompiled($compiled);
            $this->saveCompiled($template, $compiled);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function setDefaultVars(array $vars): void
    {
        $this->defaultVars = array_merge($this->defaultVars, $vars);
    }

    private function renderTemplate(string $template, array $data): string
    {
        $data = array_merge($this->defaultVars, $data);
        $this->validateData($data);
        
        return View::make($template, $data)->render();
    }

    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidVar($value)) {
                throw new TemplateException("Invalid variable: {$key}");
            }
        }
    }

    private function isValidVar($value): bool
    {
        return !is_resource($value) && 
               !is_callable($value) && 
               !$this->containsXSS($value);
    }

    private function containsXSS($value): bool
    {
        if (!is_string($value)) return false;
        
        $dangerous = ['<script', 'javascript:', 'onerror=', 'onload='];
        foreach ($dangerous as $pattern) {
            if (stripos($value, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }

    private function validateCompiled(string $compiled): void
    {
        if (empty($compiled)) {
            throw new TemplateException('Empty compilation result');
        }
    }

    private function saveCompiled(string $template, string $compiled): void
    {
        Storage::put(
            "templates/compiled/{$this->getTemplateHash($template)}.php",
            $compiled
        );
    }

    private function getTemplateHash(string $template): string
    {
        return md5($template);
    }
}

class TemplateCompiler
{
    private array $blocks = [];
    private array $extends = [];
    
    public function compile(string $template): string
    {
        $this->validateTemplate($template);
        
        $compiled = $this->compileIncludes($template);
        $compiled = $this->compileBlocks($compiled);
        $compiled = $this->compileVariables($compiled);
        $compiled = $this->compileEscaping($compiled);
        
        return $compiled;
    }

    private function validateTemplate(string $template): void
    {
        if (empty($template)) {
            throw new TemplateException('Empty template');
        }
    }

    private function compileIncludes(string $template): string
    {
        return preg_replace_callback(
            '/@include\([\'"](.*?)[\'"]\)/',
            fn($matches) => $this->loadInclude($matches[1]),
            $template
        );
    }

    private function compileBlocks(string $template): string
    {
        return preg_replace_callback(
            '/@block\([\'"](.+?)[\'"]\)(.*?)@endblock/s',
            fn($matches) => $this->processBlock($matches[1], $matches[2]),
            $template
        );
    }

    private function compileVariables(string $template): string
    {
        return preg_replace(
            '/\{\{ *(.+?) *\}\}/',
            '<?php echo htmlspecialchars($1 ?? \'\', ENT_QUOTES, \'UTF-8\'); ?>',
            $template
        );
    }

    private function compileEscaping(string $template): string
    {
        return preg_replace(
            '/\{!! *(.+?) *!!\}/',
            '<?php echo $1 ?? \'\'; ?>',
            $template
        );
    }

    private function loadInclude(string $path): string
    {
        if (!Storage::exists("templates/{$path}.blade.php")) {
            throw new TemplateException("Include not found: {$path}");
        }
        return Storage::get("templates/{$path}.blade.php");
    }

    private function processBlock(string $name, string $content): string
    {
        $this->blocks[$name] = $content;
        return "<?php echo \$this->blocks['{$name}']; ?>";
    }
}

class LayoutManager
{
    private array $layouts = [];
    
    public function register(string $name, array $sections): void
    {
        $this->validateSections($sections);
        $this->layouts[$name] = $sections;
    }

    public function render(string $layout, array $sections): string
    {
        if (!isset($this->layouts[$layout])) {
            throw new TemplateException("Layout not found: {$layout}");
        }

        $missing = array_diff(
            array_keys($this->layouts[$layout]),
            array_keys($sections)
        );

        if (!empty($missing)) {
            throw new TemplateException(
                'Missing required sections: ' . implode(', ', $missing)
            );
        }

        return View::make("layouts.{$layout}", $sections)->render();
    }

    private function validateSections(array $sections): void
    {
        if (empty($sections)) {
            throw new TemplateException('Layout must define sections');
        }
    }
}