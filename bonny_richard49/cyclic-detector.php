<?php

namespace App\Core\Validation\Architecture\Layer\Dependencies\Analysis;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\CyclicDetectorInterface;
use App\Core\Validation\Architecture\Layer\Models\{
    DependencyGraph,
    DependencyCycle,
    CyclicAnalysisResult
};

/**
 * Critical Cyclic Dependency Detector enforcing strict acyclic dependencies
 * Zero tolerance for cyclic dependencies
 */
class CyclicDetector implements CyclicDetectorInterface
{
    private DetectionRules $rules;
    private AnalysisMetrics $metrics;
    private GraphAnalyzer $graphAnalyzer;
    private array $state = [];

    public function __construct(
        DetectionRules $rules,
        AnalysisMetrics $metrics,
        GraphAnalyzer $graphAnalyzer
    ) {
        $this->rules = $rules;
        $this->metrics = $metrics;
        $this->graphAnalyzer = $graphAnalyzer;
    }

    /**
     * Detects cyclic dependencies with zero-tolerance enforcement
     *
     * @throws CyclicDependencyException
     */
    public function detectCycles(DependencyGraph $graph): CyclicAnalysisResult
    {
        // Start cycle detection
        $detectionId = $this->metrics->startDetection($graph);

        try {
            // 1. Strong Component Analysis
            $componentResults = $this->analyzeStrongComponents($graph);
            $this->metrics->recordDetectionStep('components', $componentResults);

            // 2. Dependency Chain Analysis
            $chainResults = $this->analyzeDependencyChains($graph);
            $this->metrics->recordDetectionStep('chains', $chainResults);

            // 3. Circular Reference Detection
            $referenceResults = $this->detectCircularReferences($graph);
            $this->metrics->recordDetectionStep('references', $referenceResults);

            // 4. Cycle Path Analysis
            $pathResults = $this->analyzeCyclePaths($graph);
            $this->metrics->recordDetectionStep('paths', $pathResults);

            // Validate complete cycle analysis
            $results = $this->validateResults(
                $componentResults,
                $chainResults,
                $referenceResults,
                $pathResults
            );

            // Record successful detection
            $this->metrics->recordDetectionSuccess($detectionId, $results);

            return $results;

        } catch (\Throwable $e) {
            // Record detection failure
            $this->metrics->recordDetectionFailure($detectionId, $e);
            
            // Handle cycle detection failure
            $this->handleDetectionFailure($e, $graph);
            
            throw $e;
        }
    }

    /**
     * Analyzes strongly connected components
     */
    protected function analyzeStrongComponents(DependencyGraph $graph): ComponentAnalysisResult
    {
        // Tarjan's algorithm for strong components
        $components = $this->graphAnalyzer->findStrongComponents($graph);

        foreach ($components as $component) {
            if ($component->size() > 1) {
                throw new CyclicComponentException(
                    "Cyclic dependency detected in component: {$component->getDescription()}",
                    $component->getContext()
                );
            }
        }

        return new ComponentAnalysisResult($components);
    }

    /**
     * Analyzes dependency chains for cycles
     */
    protected function analyzeDependencyChains(DependencyGraph $graph): ChainAnalysisResult
    {
        $chains = $this->graphAnalyzer->analyzeDependencyChains($graph);

        foreach ($chains as $chain) {
            if ($chain->containsCycle()) {
                throw new CyclicChainException(
                    "Cyclic dependency chain detected: {$chain->getDescription()}",
                    $chain->getContext()
                );
            }
        }

        return new ChainAnalysisResult($chains);
    }

    /**
     * Detects circular references in dependencies
     */
    protected function detectCircularReferences(DependencyGraph $graph): ReferenceAnalysisResult
    {
        $references = $this->graphAnalyzer->findCircularReferences($graph);

        foreach ($references as $reference) {
            throw new CircularReferenceException(
                "Circular reference detected: {$reference->getDescription()}",
                $reference->getContext()
            );
        }

        return new ReferenceAnalysisResult($references);
    }

    /**
     * Analyzes all possible cycle paths
     */
    protected function analyzeCyclePaths(DependencyGraph $graph): PathAnalysisResult
    {
        $pathAnalyzer = new CyclePathAnalyzer($this->rules);
        
        $paths = $pathAnalyzer->analyzePaths($graph);

        foreach ($paths as $path) {
            if ($path->formsCycle()) {
                throw new CyclicPathException(
                    "Cyclic dependency path detected: {$path->getDescription()}",
                    $path->getContext()
                );
            }
        }

        return new PathAnalysisResult($paths);
    }

    /**
     * Validates combined cycle detection results
     */
    protected function validateResults(
        ComponentAnalysisResult $componentResults,
        ChainAnalysisResult $chainResults,
        ReferenceAnalysisResult $referenceResults,
        PathAnalysisResult $pathResults
    ): CyclicAnalysisResult {
        $results = new CyclicAnalysisResult(
            $componentResults,
            $chainResults,
            $referenceResults,
            $pathResults
        );

        if (!$results->isAcyclic()) {
            throw new CyclicAnalysisException(
                'Cyclic dependency analysis failed: ' . $results->getViolationSummary()
            );
        }

        return $results;
    }

    /**
     * Handles cycle detection failures with immediate escalation
     */
    protected function handleDetectionFailure(\Throwable $e, DependencyGraph $graph): void
    {
        // Log critical cycle detection failure
        Log::critical('Critical cycle detection failure', [
            'exception' => $e,
            'graph' => $graph,
            'analysis_context' => $this->gatherAnalysisContext($e, $graph)
        ]);

        // Immediate escalation
        $this->escalateFailure($e, $graph);

        // Execute containment protocols
        $this->executeContainmentProtocols($e, $graph);
    }

    /**
     * Gathers comprehensive analysis context
     */
    protected function gatherAnalysisContext(\Throwable $e, DependencyGraph $graph): array
    {
        return [
            'failure_type' => get_class($e),
            'graph_structure' => $graph->getStructure(),
            'components' => $this->graphAnalyzer->getComponents(),
            'chains' => $this->graphAnalyzer->getChains(),
            'references' => $this->graphAnalyzer->getReferences(),
            'paths' => $this->graphAnalyzer->getPaths(),
            'detection_rules' => $this->rules->getAllRules(),
            'detection_metrics' => $this->metrics->getCurrentMetrics(),
            'analyzer_state' => $this->graphAnalyzer->getState()
        ];
    }

    /**
     * Gets current detection state
     */
    public function getAnalysisState(): array
    {
        return $this->state;
    }
}

/**
 * Analyzes dependency graph structure
 */
class GraphAnalyzer
{
    private array $components = [];
    private array $chains = [];
    private array $references = [];
    private array $paths = [];
    private array $state = [];

    public function findStrongComponents(DependencyGraph $graph): array
    {
        // Implementation would use Tarjan's algorithm
        // This is a placeholder for the concept
        return [];
    }

    public function analyzeDependencyChains(DependencyGraph $graph): array
    {
        // Implementation would analyze dependency chains
        // This is a placeholder for the concept
        return [];
    }

    public function findCircularReferences(DependencyGraph $graph): array
    {
        // Implementation would find circular references
        // This is a placeholder for the concept
        return [];
    }

    public function getComponents(): array
    {
        return $this->components;
    }

    public function getChains(): array
    {
        return $this->chains;
    }

    public function getReferences(): array
    {
        return $this->references;
    }

    public function getPaths(): array
    {
        return $this->paths;
    }

    public function getState(): array
    {
        return $this->state;
    }
}
