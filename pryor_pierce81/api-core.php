namespace App\Core\API;

class APIGateway implements APIGatewayInterface
{
    private SecurityManager $security;
    private RateLimiter $limiter;
    private RequestValidator $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function handleRequest(APIRequest $request): APIResponse
    {
        $startTime = microtime(true);

        try {
            // Rate limiting check
            $this->limiter->check($request);

            // Request validation
            $validatedRequest = $this->validator->validate($request);

            // Security verification
            $this->security->verifyRequest($validatedRequest);

            // Cache check
            $cacheKey = $this->generateCacheKey($validatedRequest);
            
            $response = $this->cache->remember($cacheKey, 3600, function() use ($validatedRequest) {
                return $this->processRequest($validatedRequest);
            });

            // Track metrics
            $this->trackMetrics($request, $response, microtime(true) - $startTime);

            return $response;

        } catch (RateLimitException $e) {
            return $this->handleRateLimit($request, $e);
        } catch (ValidationException $e) {
            return $this->handleValidationError($request, $e);
        } catch (SecurityException $e) {
            return $this->handleSecurityError($request, $e);
        } catch (\Exception $e) {
            return $this->handleSystemError($request, $e);
        }
    }

    private function processRequest(APIRequest $request): APIResponse
    {
        return DB::transaction(function() use ($request) {
            // Get handler for request
            $handler = $this->resolveHandler($request);

            // Execute with monitoring
            $result = $this->executeWithMonitoring($handler, $request);

            // Verify response
            $this->verifyResponse($result);

            return new APIResponse($result);
        });
    }

    private function executeWithMonitoring(RequestHandler $handler, APIRequest $request): mixed
    {
        $monitor = new RequestMonitor($request);

        try {
            return $monitor->execute(function() use ($handler, $request) {
                return $handler->handle($request);
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResponse($result): void
    {
        if (!$this->validator->validateResponse($result)) {
            throw new InvalidResponseException();
        }
    }

    private function trackMetrics(APIRequest $request, APIResponse $response, float $duration): void
    {
        $this->metrics->record([
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
            'status' => $response->getStatus(),
            'duration' => $duration,
            'timestamp' => time()
        ]);
    }

    private function handleRateLimit(APIRequest $request, RateLimitException $e): APIResponse
    {
        $this->logger->logRateLimit($request);

        return new APIResponse([
            'error' => 'rate_limit_exceeded',
            'retry_after' => $this->limiter->getRetryAfter($request)
        ], 429);
    }

    private function handleValidationError(APIRequest $request, ValidationException $e): APIResponse
    {
        $this->logger->logValidationError($request, $e->getErrors());

        return new APIResponse([
            'error' => 'validation_failed',
            'details' => $e->getErrors()
        ], 400);
    }

    private function handleSecurityError(APIRequest $request, SecurityException $e): APIResponse
    {
        $this->logger->logSecurityError($request, $e);

        return new APIResponse([
            'error' => 'security_error',
            'message' => 'Security validation failed'
        ], 403);
    }

    private function handleSystemError(APIRequest $request, \Exception $e): APIResponse
    {
        $this->logger->logSystemError($request, $e);

        // Don't expose internal errors
        return new APIResponse([
            'error' => 'system_error',
            'message' => 'An internal error occurred'
        ], 500);
    }

    private function generateCacheKey(APIRequest $request): string
    {
        return hash('sha256', serialize([
            $request->getPath(),
            $request->getMethod(),
            $request->getParameters(),
            $request->getUser()?->id
        ]));
    }

    private function resolveHandler(APIRequest $request): RequestHandler
    {
        $handler = $this->container->make(
            $this->routes->getHandler($request->getPath(), $request->getMethod())
        );

        if (!$handler instanceof RequestHandler) {
            throw new HandlerNotFoundException();
        }

        return $handler;
    }
}
