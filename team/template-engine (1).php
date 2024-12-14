<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Exceptions\{TemplateException, SecurityException};
use Illuminate\Support\Facades\{View, DB};

class TemplateEngine implements TemplateEngineInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $variables = [];
    private array $blocks = [];
    private array $filters = [];
    private array $securityRules;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->securityRules = $config['security_rules'] ?? [
            'allowed_tags' => ['p', 'br', 'b', 'i', 'u', 'strong', 'em', 'span', 'div', 'ul', 'ol', 'li'],
            'allowed_attrs' => ['class', 'id', 'style'],
            'allowed_protocols' => ['http', 'https', 'mailto'],
            'max_template_size' => 1048576, // 1MB
            'max_recursion' => 10
        ];
        $this->registerDefaultFilters();
    }

    public function render(string $template, array $data = [], array $options = []): string
    {
        return $this->security->executeCriticalOperation(
            function() use ($template, $data, $options) {
                // Validate template and data
                $this->validateTemplate($template);
                $this->validateData($data);

                // Generate cache key
                $cacheKey = $this->getCacheKey($template, $data);

                // Try to get from cache
                if (!($options['skip_cache'] ?? false)) {
                    if ($cached = $this->cache->get($cacheKey)) {
                        return $cached;
                    }
                }

                // Parse and render template
                $parsed = $this->parseTemplate($template);
                $rendered = $this->processTemplate($parsed, $data);

                // Sanitize output
                $result = $this->sanitizeOutput($rendered);

                // Cache the result
                if (!($options['skip_cache'] ?? false)) {
                    $this->cache->set($cacheKey, $result, $options['cache_ttl'] ?? 3600);
                }

                return $result;
            },
            ['operation' => 'template_render']
        );
    }

    public function registerBlock(string $name, callable $handler): void
    {
        $this->blocks[$name] = $handler;
    }

    public function registerFilter(string $name, callable $handler): void
    {
        $this->filters[$name] = $handler;
    }

    protected function validateTemplate(string $template): void
    {
        if (strlen($template) > $this->securityRules['max_template_size']) {
            throw new TemplateException('Template size exceeds maximum allowed size');
        }

        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateException('Invalid template structure');
        }
    }

    protected function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value) && count($value) > 1000) {
                throw new SecurityException('Data array exceeds maximum allowed size');
            }
            if (is_string($value) && strlen($value) > 100000) {
                throw new SecurityException('Data string exceeds maximum allowed size');
            }
        }
    }

    protected function parseTemplate(string $template): array
    {
        $tokens = [];
        $currentPosition = 0;
        $recursionLevel = 0;

        while ($currentPosition < strlen($template)) {
            if ($recursionLevel > $this->securityRules['max_recursion']) {
                throw new TemplateException('Maximum template recursion depth exceeded');
            }

            // Find next token
            $token = $this->findNextToken($template, $currentPosition);
            if (!$token) {
                // Add remaining text as literal
                $tokens[] = [
                    'type' => 'literal',
                    'content' => substr($template, $currentPosition)
                ];
                break;
            }

            // Add literal text before token if exists
            if ($token['position'] > $currentPosition) {
                $tokens[] = [
                    'type' => 'literal',
                    'content' => substr($template, $currentPosition, $token['position'] - $currentPosition)
                ];
            }

            // Add token
            $tokens[] = $token;
            $currentPosition = $token['position'] + strlen($token['content']);
            $recursionLevel++;
        }

        return $tokens;
    }

    protected function processTemplate(array $tokens, array $data): string
    {
        $output = '';
        foreach ($tokens as $token) {
            switch ($token['type']) {
                case 'variable':
                    $output .= $this->processVariable($token['name'], $data);
                    break;
                case 'block':
                    $output .= $this->processBlock($token['name'], $token['content'], $data);
                    break;
                case 'filter':
                    $output .= $this->processFilter($token['name'], $token['content'], $data);
                    break;
                default:
                    $output .= $token['content'];
            }
        }
        return $output;
    }

    protected function processVariable(string $name, array $data): string
    {
        $value = $data[$name] ?? '';
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    protected function processBlock(string $name, string $content, array $data): string
    {
        if (!isset($this->blocks[$name])) {
            throw new TemplateException("Undefined block: {$name}");
        }
        return ($this->blocks[$name])($content, $data);
    }

    protected function processFilter(string $name, string $content, array $data): string
    {
        if (!isset($this->filters[$name])) {
            throw new TemplateException("Undefined filter: {$name}");
        }
        return ($this->filters[$name])($content, $data);
    }

    protected function sanitizeOutput(string $output): string
    {
        $config = [
            'allowed_tags' => $this->securityRules['allowed_tags'],
            'allowed_attrs' => $this->securityRules['allowed_attrs'],
            'allowed_protocols' => $this->securityRules['allowed_protocols']
        ];

        return $this->security->sanitizeHtml($output, $config);
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template.' . md5($template . serialize($data));
    }

    protected function registerDefaultFilters(): void
    {
        $this->registerFilter('escape', function($content) {
            return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        });

        $this->registerFilter('upper', function($content) {
            return strtoupper($content);
        });

        $this->registerFilter('lower', function($content) {
            return strtolower($content);
        });

        $this->registerFilter('trim', function($content) {
            return trim($content);
        });
    }

    protected function findNextToken(string $template, int $position): ?array
    {
        // Implementation of token finding logic
        // Returns token information including type, position, content
        // This is a critical security point - must validate all tokens
        return null; // Placeholder
    }
}
