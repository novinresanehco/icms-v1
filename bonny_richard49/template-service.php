<?php

namespace App\Core\Services;

use App\Core\Interfaces\TemplateServiceInterface;
use App\Core\Security\SecurityManager;
use App\Core\System\CacheService;
use Psr\Log\LoggerInterface;
use App\Core\Exceptions\TemplateException;

class TemplateService implements TemplateServiceInterface
{
    private SecurityManager $security;
    private CacheService $cache;
    private LoggerInterface $logger;
    private array $config;
    private array $compiledTemplates = [];

    private const CACHE_PREFIX = 'template:';
    private const CACHE_TTL = 3600;
    private const COMPILE_LOCK_TIMEOUT = 30;

    public function __construct(
        SecurityManager $security,
        CacheService $cache,
        LoggerInterface $logger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->config = config('templates');
    }

    public function render(string $template, array $data = [], array $options = []): string
    {
        try {
            $this->validateTemplate($template);
            $this->validateData($data);

            $compiled = $this->compile($template);
            return $this->renderCompiled($compiled, $this->sanitizeData($data), $options);
        } catch (\Exception $e) {
            $this->handleError('Template rendering failed', $e);
        }
    }

    public function compile(string $template): string
    {
        $cacheKey = $this->getCacheKey($template);

        return $this->cache->remember($cacheKey, function() use ($template) {
            $lock = $this->cache->lock("compile:{$template}", self::COMPILE_LOCK_TIMEOUT);

            try {
                $lock->block(5);

                $compiled = $this->doCompile($template);
                $this->validateCompiled($compiled);

                return $compiled;
            } finally {
                $lock->release();
            }
        }, self::CACHE_TTL);
    }

    public function extends(string $name, callable $extension): void
    {
        $this->extensions[$name] = $extension;
    }

    public function registerFunction(string $name, callable $function): void
    {
        if (!$this->security->validateTemplateFunction($name, $function)) {
            throw new TemplateException("Invalid template function: {$name}");
        }

        $this->functions[$name] = $function;
    }

    private function doCompile(string $template): string
    {
        $compiled = $template;

        // Replace variables
        $compiled = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function($matches) {
            return "<?php echo e(" . $this->compileExpression($matches[1]) . "); ?>";
        }, $compiled);

        // Replace control structures
        $compiled = preg_replace_callback('/@(if|foreach|for|while)\s*\((.*?)\)/', function($matches) {
            return "<?php " . $matches[1] . "(" . $this->compileExpression($matches[2]) . "): ?>";
        }, $compiled);

        // Replace endings
        $compiled = preg_replace('/@end(if|foreach|for|while)/', '<?php end$1; ?>', $compiled);

        // Process includes
        $compiled = preg_replace_callback('/@include\s*\((.*?)\)/', function($matches) {
            return "<?php echo \$this->render(" . $this->compileExpression($matches[1]) . "); ?>";
        }, $compiled);

        return $compiled;
    }

    private function compileExpression(string $expression): string
    {
        // Remove dangerous functions
        $expression = preg_replace('/\b(exec|system|passthru|shell_exec|proc_open|popen|curl_exec|parse_ini_file|show_source)\b/', '', $expression);

        // Escape variables
        $expression = preg_replace('/\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/', 'e($\\1)', $expression);

        return $expression;
    }

    private function renderCompiled(string $compiled, array $data, array $options): string
    {
        extract($data);
        ob_start();

        try {
            eval('?>' . $compiled);
            return ob_get_clean();
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }
    }

    private function validateTemplate(string $template): void
    {
        if (!file_exists($template)) {
            throw new TemplateException("Template not found: {$template}");
        }

        if (!$this->security->validateTemplatePath($template)) {
            throw new TemplateException("Invalid template path: {$template}");
        }
    }

    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->security->validateTemplateData($key, $value)) {
                throw new TemplateException("Invalid template data: {$key}");
            }
        }
    }

    private function validateCompiled(string $compiled): void
    {
        if (preg_match('/<\?(?!php)/', $compiled)) {
            throw new TemplateException('Invalid PHP tags in template');
        }

        if (preg_match('/\b(exec|system|passthru|shell_exec|proc_open|popen|curl_exec|parse_ini_file|show_source)\b/', $compiled)) {
            throw new TemplateException('Dangerous functions detected in template');
        }
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    private function getCacheKey(string $template): string
    {
        return self::CACHE_PREFIX . hash('sha256', $template);
    }

    private function handleError(string $message, \Exception $e): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new TemplateException($message, 0, $e);
    }
}
