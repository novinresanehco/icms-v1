<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, File};
use App\Core\Security\SecurityManager;
use App\Exceptions\TemplateException;

class TemplateManager
{
    private SecurityManager $security;
    private array $config;
    private string $basePath;

    public function __construct(
        SecurityManager $security,
        array $config,
        string $basePath
    ) {
        $this->security = $security;
        $this->config = $config;
        $this->basePath = $basePath;
    }

    public function render(string $template, array $data = []): string
    {
        try {
            $this->validateTemplate($template);
            $compiled = $this->compileTemplate($template);
            return View::make($compiled, $this->sanitizeData($data))->render();
        } catch (\Exception $e) {
            throw new TemplateException('Template render failed: ' . $e->getMessage());
        }
    }

    protected function validateTemplate(string $template): void
    {
        $path = $this->basePath . '/' . $template;
        
        if (!File::exists($path)) {
            throw new TemplateException('Template not found');
        }

        if (!$this->isTemplateSecure($path)) {
            throw new TemplateException('Template security check failed');
        }
    }

    protected function compileTemplate(string $template): string
    {
        $cacheKey = "template:" . md5($template);
        
        return Cache::remember($cacheKey, 3600, function() use ($template) {
            $path = $this->basePath . '/' . $template;
            $content = File::get($path);
            return $this->processTemplate($content);
        });
    }

    protected function processTemplate(string $content): string
    {
        // Basic template processing
        $content = preg_replace('/\{\{(.*?)\}\}/', '<?php echo e($1); ?>', $content);
        $content = preg_replace('/@if\((.*?)\)/', '<?php if($1): ?>', $content);
        $content = preg_replace('/@endif/', '<?php endif; ?>', $content);
        $content = preg_replace('/@foreach\((.*?)\)/', '<?php foreach($1): ?>', $content);
        $content = preg_replace('/@endforeach/', '<?php endforeach; ?>', $content);
        
        return $content;
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    protected function isTemplateSecure(string $path): bool
    {
        $content = File::get($path);
        
        // Check for potentially dangerous PHP code
        if (preg_match('/<\?(?!php|=|xml)/i', $content)) {
            return false;
        }
        
        // Check for dangerous functions
        $dangerousFunctions = ['eval', 'exec', 'shell_exec', 'system', 'passthru'];
        foreach ($dangerousFunctions as $function) {
            if (stripos($content, $function) !== false) {
                return false;
            }
        }
        
        return true;
    }

    public function getTemplate(string $name): Template
    {
        $path = $this->basePath . '/' . $name;
        
        if (!File::exists($path)) {
            throw new TemplateException('Template not found');
        }

        return new Template([
            'name' => $name,
            'content' => File::get($path),
            'modified' => File::lastModified($path)
        ]);
    }

    public function updateTemplate(string $name, string $content): Template
    {
        try {
            if (!$this->isTemplateSecure($content)) {
                throw new TemplateException('Template content not secure');
            }

            $path = $this->basePath . '/' . $name;
            File::put($path, $content);
            Cache::tags(['templates'])->flush();

            return new Template([
                'name' => $name,
                'content' => $content,
                'modified' => time()
            ]);
        } catch (\Exception $e) {
            throw new TemplateException('Template update failed: ' . $e->getMessage());
        }
    }

    public function deleteTemplate(string $name): bool
    {
        try {
            $path = $this->basePath . '/' . $name;
            if (File::exists($path)) {
                File::delete($path);
                Cache::tags(['templates'])->flush();
                return true;
            }
            return false;
        } catch (\Exception $e) {
            throw new TemplateException('Template deletion failed: ' . $e->getMessage());
        }
    }
}

class Template
{
    public readonly string $name;
    public readonly string $content;
    public readonly int $modified;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->content = $data['content'];
        $this->modified = $data['modified'];
    }
}
