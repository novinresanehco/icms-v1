<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

/**
 * Core template engine with integrated security and caching
 */
class TemplateEngine implements TemplateEngineInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private TemplateMetricsRepository $metrics;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        TemplateMetricsRepository $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    /**
     * Render template with comprehensive security and caching
     */
    public function render(string $template, array $data): string
    {
        $startTime = microtime(true);

        try {
            // Validate input
            $this->validator->validateTemplate($template);
            $this->validator->validateData($data);

            // Generate cache key
            $cacheKey = $this->generateCacheKey($template, $data);

            // Try to get from cache
            if ($cached = $this->cache->get($cacheKey)) {
                $this->metrics->recordCacheHit($template);
                return $cached;
            }

            // Compile and render
            $compiled = $this->compile($template);
            $result = $this->renderCompiled($compiled, $data);

            // Store in cache
            $this->cache->set($cacheKey, $result);
            
            // Record metrics
            $this->recordMetrics($template, microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            $this->handleError($e, $template);
            throw $e;
        }
    }

    /**
     * Compile template with security checks
     */
    public function compile(string $template): CompiledTemplate
    {
        // Validate template
        $this->security->validateTemplate($template);

        // Parse and compile
        $ast = $this->parse($template);
        $this->validateAst($ast);
        
        return new CompiledTemplate($ast);
    }

    /**
     * Render compiled template with data validation
     */
    private function renderCompiled(CompiledTemplate $template, array $data): string
    {
        // Validate context data
        $this->validator->validateRenderContext($data);

        // Create secure render context
        $context = $this->createRenderContext($data);

        // Render with monitoring
        return $template->render($context);
    }

    /**
     * Generate secure cache key
     */
    private function generateCacheKey(string $template, array $data): string
    {
        return hash('sha256', $template . serialize($data));
    }

    /**
     * Record performance metrics
     */
    private function recordMetrics(string $template, float $duration): void
    {
        $this->metrics->record([
            'template' => $template,
            'render_time' => $duration,
            'memory_used' => memory_get_peak_usage(true),
            'timestamp' => time()
        ]);
    }

    /**
     * Handle template errors with logging
     */
    private function handleError(\Exception $e, string $template): void
    {
        $this->metrics->recordError([
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * Template metrics repository for monitoring
 */
class TemplateMetricsRepository
{
    private \PDO $db;

    public function record(array $metrics): void
    {
        $sql = "INSERT INTO template_metrics (name, value, tags, timestamp, environment) 
                VALUES (:name, :value, :tags, :timestamp, :environment)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'name' => $metrics['template'],
            'value' => $metrics['render_time'],
            'tags' => json_encode(['memory' => $metrics['memory_used']]),
            'timestamp' => date('Y-m-d H:i:s', $metrics['timestamp']),
            'environment' => APP_ENV
        ]);
    }

    public function recordError(array $error): void
    {
        $sql = "INSERT INTO template_errors (message, level, context, environment)
                VALUES (:message, :level, :context, :environment)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'message' => $error['error'],
            'level' => 'error',
            'context' => json_encode([
                'template' => $error['template'],
                'trace' => $error['trace']
            ]),
            'environment' => APP_ENV
        ]);
    }

    public function recordCacheHit(string $template): void
    {
        $this->record([
            'template' => $template,
            'render_time' => 0,
            'memory_used' => 0,
            'timestamp' => time(),
            'cache_hit' => true
        ]);
    }
}
