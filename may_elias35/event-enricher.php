<?php

namespace App\Core\Audit;

class EventEnricher
{
    private ContextProvider $contextProvider;
    private RelationshipResolver $relationshipResolver;
    private MetadataGenerator $metadataGenerator;
    private SecurityContext $securityContext;
    private array $enrichers;

    public function __construct(
        ContextProvider $contextProvider,
        RelationshipResolver $relationshipResolver,
        MetadataGenerator $metadataGenerator,
        SecurityContext $securityContext
    ) {
        $this->contextProvider = $contextProvider;
        $this->relationshipResolver = $relationshipResolver;
        $this->metadataGenerator = $metadataGenerator;
        $this->securityContext = $securityContext;
        $this->enrichers = [];
    }

    public function registerEnricher(string $type, callable $enricher): void
    {
        if (isset($this->enrichers[$type])) {
            throw new DuplicateEnricherException("Enricher already registered for type: {$type}");
        }

        $this->enrichers[$type] = $enricher;
    }

    public function enrich(array $data, array $context = []): array
    {
        try {
            // Add basic context
            $enrichedData = $this->addBasicContext($data);

            // Add security context
            $enrichedData = $this->addSecurityContext($enrichedData);

            // Add relationships
            $enrichedData = $this->addRelationships($enrichedData);

            // Add custom metadata
            $enrichedData = $this->addCustomMetadata($enrichedData);

            // Apply type-specific enrichers
            $enrichedData = $this->applyTypeEnrichers($enrichedData);

            // Add performance metrics
            $enrichedData = $this->addPerformanceMetrics($enrichedData);

            // Add custom context
            $enrichedData = array_merge($enrichedData, $context);

            return $enrichedData;

        } catch (\Exception $e) {
            throw new EventEnrichmentException(
                'Failed to enrich event data: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    protected function addBasicContext(array $data): array
    {
        return array_merge($data, [
            'environment' => $this->contextProvider->getEnvironment(),
            'application' => $this->contextProvider->getApplicationInfo(),
            'server' => $this->contextProvider->getServerInfo(),
            'process' => [
                'id' => getmypid(),
                'memory_usage' => memory_get_usage(true),
                'peak_memory_usage' => memory_get_peak_usage(true)
            ]
        ]);
    }

    protected function addSecurityContext(array $data): array
    {
        return array_merge($data, [
            'security_context' => [
                'user' => $this->securityContext->getCurrentUser(),
                'roles' => $this->securityContext->getCurrentRoles(),
                'permissions' => $this->securityContext->getCurrentPermissions(),
                'session' => $this->securityContext->getSessionInfo(),
                'ip_address' => $this->securityContext->getClientIp(),
                'user_agent' => $this->securityContext->getUserAgent()
            ]
        ]);
    }

    protected function addRelationships(array $data): array
    {
        if (!isset($data['entity_type']) || !isset($data['entity_id'])) {
            return $data;
        }

        $relationships = $this->relationshipResolver->resolveRelationships(
            $data['entity_type'],
            $data['entity_id']
        );

        return array_merge($data, ['relationships' => $relationships]);
    }

    protected function addCustomMetadata(array $data): array
    {
        $metadata = $this->metadataGenerator->generate($data);

        return array_merge($data, ['metadata' => $metadata]);
    }

    protected function applyTypeEnrichers(array $data): array
    {
        if (!isset($data['type']) || !isset($this->enrichers[$data['type']])) {
            return $data;
        }

        try {
            $enrichedData = ($this->enrichers[$data['type']])($data);
            
            if (!is_array($enrichedData)) {
                throw new InvalidEnricherResultException(
                    "Enricher for {$data['type']} must return array"
                );
            }

            return $enrichedData;

        } catch (\Exception $e) {
            throw new EnricherExecutionException(
                "Failed to execute enricher for type {$data['type']}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    protected function addPerformanceMetrics(array $data): array
    {
        return array_merge($data, [
            'performance_metrics' => [
                'processing_time' => $this->calculateProcessingTime(),
                'memory_delta' => $this->calculateMemoryDelta(),
                'query_count' => $this->getQueryCount(),
                'cache_hits' => $this->getCacheHits()
            ]
        ]);
    }

    private function calculateProcessingTime(): float
    {
        return microtime(true) - LARAVEL_START;
    }

    private function calculateMemoryDelta(): int
    {
        return memory_get_usage(true) - $this->initialMemoryUsage;
    }

    private function getQueryCount(): int
    {
        return \DB::getQueryLog()->count();
    }

    private function getCacheHits(): array
    {
        return [
            'hits' => cache()->getHits(),
            'misses' => cache()->getMisses()
        ];
    }
}
