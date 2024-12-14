<?php

namespace App\Core\Template\Response;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use App\Core\Template\Exceptions\ResponseException;

class ResponseManager
{
    private array $config;
    private HeaderManager $headers;
    private CacheControl $cache;
    private CompressionManager $compression;

    public function __construct(
        HeaderManager $headers,
        CacheControl $cache,
        CompressionManager $compression,
        array $config = []
    ) {
        $this->headers = $headers;
        $this->cache = $cache;
        $this->compression = $compression;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Create response from template
     *
     * @param string $content
     * @param array $options
     * @return Response
     */
    public function makeResponse(string $content, array $options = []): Response
    {
        try {
            // Process content
            $content = $this->processContent($content, $options);

            // Create response
            $response = new Response($content, $options['status'] ?? 200);

            // Add headers
            $this->headers->addHeaders($response, $options['headers'] ?? []);

            // Configure caching
            $this->cache->configure($response, $options['cache'] ?? []);

            // Apply compression
            $this->compression->compress($response, $options['compression'] ?? []);

            return $response;
        } catch (\Exception $e) {
            throw new ResponseException("Failed to create response: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Process response content
     *
     * @param string $content
     * @param array $options
     * @return string
     */
    protected function processContent(string $content, array $options): string
    {
        // Minify if enabled
        if ($options['minify'] ?? $this->config['minify_enabled']) {
            $content = $this->minifyContent($content);
        }

        // Process includes
        if ($options['process_includes'] ?? true) {
            $content = $this->processIncludes($content);
        }

        return $content;
    }

    /**
     * Minify content
     *
     * @param string $content
     * @return string
     */
    protected function minifyContent(string $content): string
    {
        // Remove comments
        $content = preg_replace('/<!--(?!<!)[^\[>].*?-->/s', '', $content);
        
        // Remove whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        return trim($content);
    }

    /**
     * Process includes in content
     *
     * @param string $content
     * @return string
     */
    protected function processIncludes(string $content): string
    {
        return preg_replace_callback(
            '/@include\(\'([^\']+)\'\)/',
            function ($matches) {
                return $this->loadInclude($matches[1]);
            },
            $content
        );
    }

    /**
     * Load included content
     *
     * @param string $path
     * @return string
     */
    protected function loadInclude(string $path): string
    {
        $fullPath = resource_path("views/{$path}.blade.php");
        
        if (!file_exists($fullPath)) {
            throw new ResponseException("Include file not found: {$path}");
        }

        return file_get_contents($fullPath);
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'minify_enabled' => true,
            'compression_enabled' => true,
            'default_cache_ttl' => 3600,
            'security_headers_enabled' => true
        ];
    }
}

class HeaderManager
{
    private array $securityHeaders = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-XSS-Protection' => '1; mode=block',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'"
    ];

    /**
     * Add headers to response
     *
     * @param Response $response
     * @param array $headers
     * @return void
     */
    public function addHeaders(Response $response, array $headers = []): void
    {
        // Add security headers
        foreach ($this->securityHeaders as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Add custom headers
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Add powered by header
        $response->headers->set('X-Powered-By', 'ICMS');
    }

    /**
     * Add CORS headers
     *
     * @param Response $response
     * @param array $config
     * @return void
     */
    public function addCorsHeaders(Response $response, array $config = []): void
    {
        $response->headers->set(
            'Access-Control-Allow-Origin', 
            $config['allowed_origins'] ?? '*'
        );

        $response->headers->set(
            'Access-Control-Allow-Methods',
            $config['allowed_methods'] ?? 'GET, POST, PUT, DELETE, OPTIONS'
        );

        $response->headers->set(
            'Access-Control-Allow-Headers',
            $config['allowed_headers'] ?? 'Content-Type, Authorization'
        );
    }
}

class CacheControl
{
    /**
     * Configure response caching
     *
     * @param Response $response
     * @param array $options
     * @return void
     */
    public function configure(Response $response, array $options = []): void
    {
        if ($options['no_cache'] ?? false) {
            $this->disableCache($response);
            return;
        }

        $response->setPublic();
        $response->setMaxAge($options['max_age'] ?? 3600);
        $response->setSharedMaxAge($options['shared_max_age'] ?? 3600);

        if ($options['etag'] ?? true) {
            $response->setEtag(md5($response->getContent()));
        }

        if ($options['last_modified'] ?? null) {
            $response->setLastModified($options['last_modified']);
        }
    }

    /**
     * Disable caching for response
     *
     * @param Response $response
     * @return void
     */
    protected function disableCache(Response $response): void
    {
        $response->setPrivate();
        $response->setNoStore();
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->headers->addCacheControlDirective('no-store', true);
        $response->headers->addCacheControlDirective('no-cache', true);
    }
}

class CompressionManager
{
    /**
     * Compress response content
     *
     * @param Response $response
     * @param array $options
     * @return void
     */
    public function compress(Response $response, array $options = []): void
    {
        if (!$this->shouldCompress($response, $options)) {
            return;
        }

        $content = $response->getContent();
        $encoding = $this->getPreferredEncoding();

        switch ($encoding) {
            case 'gzip':
                $response->setContent(gzencode($content, $options['level'] ?? -1));
                $response->headers->set('Content-Encoding', 'gzip');
                break;

            case 'deflate':
                $response->setContent(gzdeflate($content, $options['level'] ?? -1));
                $response->headers->set('Content-Encoding', 'deflate');
                break;
        }

        $response->headers->remove('Content-Length');
    }

    /**
     * Check if response should be compressed
     *
     * @param Response $response
     * @param array $options
     * @return bool
     */
    protected function shouldCompress(Response $response, array $options): bool
    {
        // Check if compression is enabled
        if (!($options['enabled'] ?? true)) {
            return false;
        }

        // Check content type
        $contentType = $response->headers->get('Content-Type', '');
        if (!$this->isCompressibleContentType($contentType)) {
            return false;
        }

        // Check content length
        $minLength = $options['min_length'] ?? 1024;
        return strlen($response->getContent()) >= $minLength;
    }

    /**
     * Get preferred encoding method
     *
     * @return string|null
     */
    protected function getPreferredEncoding(): ?string
    {
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        
        if (strpos($acceptEncoding, 'gzip') !== false) {
            return 'gzip';
        }
        
        if (strpos($acceptEncoding, 'deflate') !== false) {
            return 'deflate';
        }

        return null;
    }

    /**
     * Check if content type is compressible
     *
     * @param string $contentType
     * @return bool
     */
    protected function isCompressibleContentType(string $contentType): bool
    {
        $compressibleTypes = [
            'text/html',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/json',
            'application/xml'
        ];

        foreach ($compressibleTypes as $type) {
            if (strpos($contentType, $type) !== false) {
                return true;
            }
        }

        return false;
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Response\ResponseManager;
use App\Core\Template\Response\HeaderManager;
use App\Core\Template\Response\CacheControl;
use App\Core\Template\Response\CompressionManager;

class ResponseServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ResponseManager::class, function ($app) {
            return new ResponseManager(
                new HeaderManager(),
                new CacheControl(),
                new CompressionManager(),
                config('response')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Register middleware for response handling
        $this->app['router']->pushMiddleware(\App\Http\Middleware\ResponseHandler::class);
    }
}
