<?php

namespace App\Core\Validation\Architecture\Layer;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\LayerValidatorInterface;
use App\Core\Validation\Architecture\Layer\Models\{
    LayerDefinition,
    LayerViolation,
    LayerAnalysisResult
};

/**
 * Critical Layer Validator enforcing strict architectural layer compliance
 * Zero tolerance for layer violations
 */
class LayerValidator implements LayerValidatorInterface
{
    private LayerRules $rules;
    private AnalysisMetrics $metrics;
    private DependencyAnalyzer $dependencyAnalyzer;
    private IsolationVerifier $isolationVerifier;

    public function __construct(
        LayerRules $rules,
        AnalysisMetrics $metrics,
        DependencyAnalyzer $dependencyAnalyzer,
        IsolationVerifier $isolationVerifier
    ) {
        $this->rules = $rules;
        $this->metrics = $metrics;
        $this->dependencyAnalyzer = $dependencyAnalyzer;
        $this->isolationVerifier = $isolationVerifier;
    }

    /**
     * Validates architectural layer compliance with zero-tolerance
     *
     * @throws LayerViolationException
     */
    public function validateLayers(
        array $layers,
        array $rules
    ): LayerAnalysisResult {
        // Start layer analysis
        $analysisId = $this->metrics->startAnalysis($layers);

        try {
            // 1. Layer Separation Validation
            $separationResults = $this->validateLayerSeparation($layers);
            $this->metrics->recordAnalysisStep('separation', $separationResults);

            // 2. Layer Dependency Analysis
            $dependencyResults = $this->validateLayerDependencies($layers);
            $this->metrics->recordAnalysisStep('dependencies', $dependencyResults);

            // 3. Layer Isolation Verification
            $isolationResults = $this->validateLayerIsolation($layers);
            $this->metrics->recordAnalysisStep('isolation', $isolationResults);

            // 4. Layer Interface Compliance
            $interfaceResults = $this->validateLayerInterfaces($layers);
            $this->metrics->recordAnalysisStep('interfaces', $interfaceResults);

            // Validate complete layer analysis
            $results = $this->validateResults(
                $separationResults,
                $dependencyResults,
                $isolationResults,
                $interfaceResults
            );

            // Record successful analysis
            $this->metrics->recordAnalysisSuccess($analysisId, $results);

            return $results;

        } catch (\Throwable $e) {
            // Record analysis failure
            $this->metrics->recordAnalysisFailure($analysisId, $e);
            
            // Handle layer violation
            $this->handleLayerViolation($e, $layers);
            
            throw $e;
        }
    }

    /**
     * Validates strict layer separation
     */
    protected function validateLayerSeparation(array $layers): LayerSeparationResult
    {
        foreach ($layers as $layer) {
            // Verify layer boundaries
            if (!$this->verifyLayerBoundaries($layer)) {
                throw new LayerViolationException(
                    "Layer boundary violation detected in {$layer->getName()}",
                    $this->getViolationContext($layer)
                );
            }

            // Verify component placement
            if (!$this->verifyComponentPlacement($layer)) {
                throw new LayerViolationException(
                    "Invalid component placement in layer {$layer->getName()}",
                    $this->getViolationContext($layer)
                );
            }

            // Verify namespace isolation
            if (!$this->verifyNamespaceIsolation($layer)) {
                throw new LayerViolationException(
                    "Namespace isolation violation in layer {$layer->getName()}",
                    $this->getViolationContext($layer)
                );
            }
        }

        return new LayerSeparationResult($layers);
    }

    /**
     * Validates layer dependency rules
     */
    protected function validateLayerDependencies(array $layers): LayerDependencyResult
    {
        $results = $this->dependencyAnalyzer->analyzeDependencies(
            $layers,
            $this->rules->getDependencyRules()
        );

        foreach ($results->getViolations() as $violation) {
            throw new LayerDependencyException(
                "Layer dependency violation: {$violation->getDescription()}",
                $violation->getContext()
            );
        }

        return $results;
    }

    /**
     * Validates layer isolation requirements
     */
    protected function validateLayerIsolation(array $layers): LayerIsolationResult
    {
        $results = $this->isolationVerifier->verifyIsolation(
            $layers,
            $this->rules->getIsolationRules()
        );

        foreach ($results->getViolations() as $violation) {
            throw new LayerIsolationException(
                "Layer isolation violation: {$violation->getDescription()}",
                $violation->getContext()
            );
        }

        return $results;
    }

    /**
     * Validates layer interface compliance
     */
    protected function validateLayerInterfaces(array $layers): LayerInterfaceResult
    {
        $validator = new LayerInterfaceValidator($this->rules->getInterfaceRules());
        
        $results = $validator->validateInterfaces(
            $layers,
            $this->rules->getInterfaceDefinitions()
        );

        foreach ($results->getViolations() as $violation) {
            throw new LayerInterfaceException(
                "Layer interface violation: {$violation->getDescription()}",
                $violation->getContext()
            );
        }

        return $results;
    }

    /**
     * Validates combined layer analysis results
     */
    protected function validateResults(
        LayerSeparationResult $separationResults,
        LayerDependencyResult $dependencyResults,
        LayerIsolationResult $isolationResults,
        LayerInterfaceResult $interfaceResults
    ): LayerAnalysisResult {
        $results = new LayerAnalysisResult(
            $separationResults,
            $dependencyResults,
            $isolationResults,
            $interfaceResults
        );

        if (!$results->isLayerCompliant()) {
            throw new LayerAnalysisException(
                'Layer analysis failed: ' . $results->getViolationSummary()
            );
        }

        return $results;
    }

    /**
     * Verifies layer boundary compliance
     */
    protected function verifyLayerBoundaries(LayerDefinition $layer): bool
    {
        // Verify layer entry points
        if (!$this->verifyEntryPoints($layer)) {
            return false;
        }

        // Verify layer exit points
        if (!$this->verifyExitPoints($layer)) {
            return false;
        }

        // Verify internal structure
        if (!$this->verifyInternalStructure($layer)) {
            return false;
        }

        return true;
    }

    /**
     * Verifies component placement in layers
     */
    protected function verifyComponentPlacement(LayerDefinition $layer): bool
    {
        foreach ($layer->getComponents() as $component) {
            // Verify component layer alignment
            if (!$this->verifyComponentLayerAlignment($component, $layer)) {
                return false;
            }

            // Verify component dependencies
            if (!$this->verifyComponentDependencies($component, $layer)) {
                return false;
            }

            // Verify component interfaces
            if (!$this->verifyComponentInterfaces($component, $layer)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifies namespace isolation between layers
     */
    protected function verifyNamespaceIsolation(LayerDefinition $layer): bool
    {
        // Verify namespace boundaries
        if (!$this->verifyNamespaceBoundaries($layer)) {
            return false;
        }

        // Verify namespace dependencies
        if (!$this->verifyNamespaceDependencies($layer)) {
            return false;
        }

        // Verify namespace interfaces
        if (!$this->verifyNamespaceInterfaces($layer)) {
            return false;
        }

        return true;
    }

    /**
     * Handles layer violations with immediate escalation
     */
    protected function handleLayerViolation(\Throwable $e, array $layers): void
    {
        // Log critical layer violation
        Log::critical('Critical layer architecture violation detected', [
            'exception' => $e,
            'layers' => $layers,
            'analysis_context' => $this->gatherAnalysisContext($e, $layers)
        ]);

        // Immediate escalation
        $this->escalateViolation($e, $layers);

        // Execute containment protocols
        $this->executeContainmentProtocols($e, $layers);
    }

    /**
     * Gathers comprehensive analysis context
     */
    protected function gatherAnalysisContext(\Throwable $e, array $layers): array
    {
        return [
            'violation_type' => get_class($e),
            'layer_structure' => $this->getLayerStructure($layers),
            'dependencies' => $this->getLayerDependencies($layers),
            'interfaces' => $this->getLayerInterfaces($layers),
            'namespace_map' => $this->getNamespaceMap($layers),
            'validation_rules' => $this->rules->getAllRules(),
            'analysis_metrics' => $this->metrics->getCurrentMetrics()
        ];
    }
}
