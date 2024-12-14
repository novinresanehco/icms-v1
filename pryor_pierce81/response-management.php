namespace App\Core\Response;

class ResponseManager implements ResponseInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private ValidatorService $validator;
    private HeaderManager $headers;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MetricsCollector $metrics,
        ValidatorService $validator,
        HeaderManager $headers
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->headers = $headers;
        $this->loadConfiguration();
    }

    public function send($data, int $status = 200): Response
    {
        $startTime = microtime(true);

        try {
            return $this->security->executeCriticalOperation(
                new ResponseOperation(
                    $this->validateData($data),
                    $status,
                    $this->buildHeaders(),
                    $this->validator
                ),
                SecurityContext::fromRequest()
            );
        } finally {
            $this->metrics->timing(
                'response.send.duration',
                microtime(true) - $startTime
            );
        }
    }

    public function json($data, int $status = 200): JsonResponse
    {
        $this->validateJsonData($data);

        $response = new JsonResponse(
            $this->sanitizeData($data),
            $status,
            $this->buildSecureHeaders()
        );

        return $this->finalizeResponse($response);
    }

    public function stream(string $path, string $name = null): StreamedResponse
    {
        return $this->security->executeCriticalOperation(
            new StreamOperation(
                $path,
                $name,
                $this->headers,
                $this->validator
            ),
            SecurityContext::fromRequest()
        );
    }

    public function download(string $path, string $name = null): BinaryFileResponse
    {
        return $this->security->executeCriticalOperation(
            new DownloadOperation(
                $path,
                $name,
                $this->headers,
                $this->validator
            ),
            SecurityContext::fromRequest()
        );
    }

    private function validateData($data): mixed
    {
        if (is_array($data)) {
            return $this->validateArrayData($data);
        }

        if (is_string($data)) {
            return $this->validateStringData($data);
        }

        if ($data instanceof ResponseData) {
            return $data->validate($this->validator);
        }

        return $data;
    }

    private function validateArrayData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->validateArrayData($value);
            } else {
                $data[$key] = $this->validateValue($value);
            }
        }

        return $data;
    }

    private function validateStringData(string $data): string
    {
        if ($this->containsHtml($data)) {
            return $this->sanitizeHtml($data);
        }

        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }

    private function validateJsonData($data): void
    {
        if (!$this->validator->validateJson($data)) {
            throw new InvalidJsonException('Invalid JSON data structure');
        }
    }

    private function buildSecureHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Content-Security-Policy' => $this->config['csp'],
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ];
    }

    private function sanitizeData($data): mixed
    {
        if (is_array($data)) {
            array_walk_recursive($data, function (&$value) {
                if (is_string($value)) {
                    $value = $this->validateStringData($value);
                }
            });
        }

        return $data;
    }

    private function containsHtml(string $data): bool
    {
        return preg_match('/<[^>]*>/', $data) === 1;
    }

    private function sanitizeHtml(string $html): string
    {
        return $this->security->executeCriticalOperation(
            new HtmlSanitizeOperation(
                $html,
                $this->config['allowed_tags'],
                $this->config['allowed_attributes']
            ),
            SecurityContext::fromRequest()
        );
    }

    private function finalizeResponse(Response $response): Response
    {
        $response->headers->add($this->buildSecureHeaders());
        
        if ($this->shouldCache($response)) {
            $this->cacheResponse($response);
        }

        return $response;
    }

    private function shouldCache(Response $response): bool
    {
        return $response->getStatusCode() === 200 
            && request()->isMethodCacheable()
            && !request()->headers->has('Authorization');
    }

    private function cacheResponse(Response $response): void
    {
        $key = $this->generateCacheKey(request());
        
        $this->cache->put(
            $key,
            [
                'content' => $response->getContent(),
                'headers' => $response->headers->all()
            ],
            $this->config['cache_ttl']
        );
    }

    private function generateCacheKey(Request $request): string
    {
        return md5(
            $request->getUri() . 
            serialize($request->query->all()) .
            serialize($request->headers->all())
        );
    }

    private function loadConfiguration(): void
    {
        $this->config = [
            'cache_ttl' => config('response.cache_ttl', 3600),
            'allowed_tags' => config('response.allowed_tags', []),
            'allowed_attributes' => config('response.allowed_attributes', []),
            'csp' => config('response.csp', "default-src 'self'")
        ];
    }
}
