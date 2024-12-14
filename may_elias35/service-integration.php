<?php

namespace App\Core\Integration;

class ServiceIntegrationManager
{
    private ServiceRegistry $registry;
    private LoadBalancer $loadBalancer;
    private CircuitBreaker $circuitBreaker;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function __construct(
        ServiceRegistry $registry,
        LoadBalancer $loadBalancer,
        CircuitBreaker $circuitBreaker,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->registry = $registry;
        $this->loadBalancer = $loadBalancer;
        $this->circuitBreaker = $circuitBreaker;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function executeServiceCall(ServiceRequest $request): ServiceResponse
    {
        $service = $this->registry->getService($request->getServiceName());
        
        if (!$service->isAvailable()) {
            throw new ServiceUnavailableException($service->getName());
        }

        if (!$this->circuitBreaker->canExecute($service)) {
            return $this->handleFailover($request, $service);
        }

        try {
            $startTime = microtime(true);
            
            $response = $this->executeRequest($request, $service);
            
            $this->metrics->recordSuccess($service, microtime(true) - $startTime);
            $this->circuitBreaker->recordSuccess($service);

            return $response;

        } catch (ServiceException $e) {
            $this->handleFailure($service, $e);
            throw $e;
        }
    }

    protected function executeRequest(ServiceRequest $request, Service $service): ServiceResponse
    {
        // Try cache first if caching is enabled for this request
        if ($request->isCacheable()) {
            $cacheKey = $this->generateCacheKey($request);
            
            $cachedResponse = $this->cache->get($cacheKey);
            if ($cachedResponse !== null) {
                return $cachedResponse;
            }
        }

        // Get optimal node for execution
        $node = $this->loadBalancer->getOptimalNode($service);

        $response = $node->executeRequest($request);

        // Cache the response if caching is enabled
        if ($request->isCacheable()) {
            $this->cache->set(
                $cacheKey,
                $response,
                $request->getCacheTTL()
            );
        }

        return $response;
    }

    protected function handleFailover(ServiceRequest $request, Service $service): ServiceResponse
    {
        $fallbackService = $this->registry->getFallbackService($service);
        
        if (!$fallbackService) {
            throw new NoFallbackAvailableException($service->getName());
        }

        return $this->executeServiceCall(
            $request->withService($fallbackService)
        );
    }

    protected function handleFailure(Service $service, ServiceException $e): void
    {
        $this->circuitBreaker->recordFailure($service);
        $this->metrics->recordFailure($service, $e);
        
        if ($this->shouldTriggerAlert($service, $e)) {
            $this->triggerAlert($service, $e);
        }
    }

    protected function generateCacheKey(ServiceRequest $request): string
    {
        return sprintf(
            'service:%s:%s:%s',
            $request->getServiceName(),
            $request->getOperation(),
            md5(serialize($request->getParameters()))
        );
    }

    protected function shouldTriggerAlert(Service $service, ServiceException $e): bool
    {
        return $this->metrics->getFailureRate($service) > $service->getAlertThreshold()
            || $e->isCritical();
    }

    protected function triggerAlert(Service $service, ServiceException $e): void
    {
        event(new ServiceFailureEvent($service, $e));
    }
}
