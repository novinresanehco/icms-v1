<?php

namespace App\Core\Rendering;

use App\Models\Template;
use App\Core\Exceptions\TemplateRenderException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;

class TemplateRenderer
{
    protected array $globals = [];
    protected array $directives = [];

    public function render(Template $template, array $data = []): string
    {
        try {
            $compiled = $this->compile($template);
            return $this->evaluate($compiled, array_merge($this->globals, $data));
        } catch (\Exception $e) {
            throw new TemplateRenderException("Failed to render template: {$e->getMessage()}", 0, $e);
        }
    }

    public function compile(Template $template): string
    {
        return Cache::tags(['templates'])->remember(
            "template.compiled.{$template->id}",
            3600,
            function() use ($template) {
                $content = $this->processIncludes($template->content);
                $content = $this->processDirectives($content);
                return Blade::compileString($content);
            }
        );
    }

    public function addGlobal(string $key, $value): void
    {
        $this->globals[$key] = $value;
    }

    public function addDirective(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
        Blade::directive($name, $handler);
    }

    protected function evaluate(string $compiled, array $data): string
    {
        $__data = array_merge($this->globals, $data);
        $obLevel = ob_get_level();
        ob_start();
        extract($__data, EXTR_SKIP);

        try {
            eval('?' . '>' . $compiled);
        } catch (\Exception $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            throw $e;
        }

        return ob_get_clean();
    }

    protected function processIncludes(string $content): string
    {
        return preg_replace_callback(
            '/@include\([\'"]([^\'"]+)[\'"](,\s*(\[.*?\]))?\)/',
            function($matches) {
                $view = $matches[1];
                $data = isset($matches[3]) ? eval('return ' . $matches[3] . ';') : [];
                return View::make($view, $data)->render();
            },
            $content
        );
    }

    protected function processDirectives(string $content): string
    {
        foreach ($this->directives as $name => $handler) {
            $pattern = "/@{$name}\((.*?)\)/";
            $content = preg_replace_callback($pattern, function($matches) use ($handler) {
                return $handler($matches[1]);
            }, $content);
        }
        return $content;
    }
}
