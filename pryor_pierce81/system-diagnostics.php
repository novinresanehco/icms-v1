<?php

namespace App\Core\Diagnostics;

class SystemDiagnosticsService implements DiagnosticsInterface
{
    private DiagnosticEngine $engine;
    private ResultAnalyzer $analyzer;
    private PerformanceValidator $validator;
    private DiagnosticsLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        DiagnosticEngine $engine,
        ResultAnalyzer $analyzer,
        PerformanceValidator $validator,
        DiagnosticsLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->engine = $engine;
        $this->analyzer = $analyzer;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function performDiagnostics(DiagnosticContext $context): DiagnosticResult
    {
        $diagnosticId = $this->initializeDiagnostics($context);
        
        try {
            DB::beginTransaction();

            $testSuite = $this->prepareDiagnosticSuite($context);
            $this->validateTestSuite($testSuite);

            $results = $this->engine->runDiagnostics($testSuite);
            $analysis = $this->analyzer->analyzeResults($results);

            $this->validateResults($analysis);

            $result = new DiagnosticResult([
                'diagnosticId' => $diagnosticId,
                'results' => $results,
                'analysis' => $analysis,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (DiagnosticException $e) {
            DB::rollBack();
            $this->handleDiagnosticFailure($e, $diagnosticId);
            throw new CriticalDiagnosticException($e->getMessage(), $e);
        }
    }

    private function prepareDiagnosticSuite(DiagnosticContext $context): TestSuite
    {
        $suite = $this->engine->prepareSuite($context);
        
        if (!$suite->isValid()) {
            throw new InvalidTestSuiteException('Invalid diagnostic test suite');
        }
        
        return $suite;
    }

    private function validateResults(DiagnosticAnalysis $analysis): void
    {
        if ($analysis->hasCriticalIssues()) {
            $this->emergency->handleCriticalIssues($analysis);
            throw new CriticalIssuesException('Critical system issues detected');
        }

        if (!$this->validator->validatePerformance($analysis)) {
            throw new PerformanceException('System performance below critical threshold');
        }
    }

    private function handleDiagnosticFailure(
        DiagnosticException $e,
        string $diagnosticId
    ): void {
        $this->logger->logFailure($e, $diagnosticId);
        
        if ($e->isCritical()) {
            $this->emergency->initiateCriticalProtocol();
            $this->alerts->dispatchCriticalAlert(
                new DiagnosticFailureAlert($e, $diagnosticId)
            );
        }
    }
}
