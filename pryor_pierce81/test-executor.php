<?php

namespace App\Core\Testing;

class CriticalTestExecutor implements TestExecutorInterface
{
    private TestRegistry $testRegistry;
    private ValidationChain $validationChain;
    private ResultAnalyzer $resultAnalyzer;
    private CoverageVerifier $coverageVerifier;
    private TestLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        TestRegistry $testRegistry,
        ValidationChain $validationChain,
        ResultAnalyzer $resultAnalyzer,
        CoverageVerifier $coverageVerifier,
        TestLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->testRegistry = $testRegistry;
        $this->validationChain = $validationChain;
        $this->resultAnalyzer = $resultAnalyzer;
        $this->coverageVerifier = $coverageVerifier;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function executeCriticalTests(TestContext $context): TestResult
    {
        $executionId = $this->initializeExecution($context);
        
        try {
            DB::beginTransaction();

            $testSuite = $this->testRegistry->getCriticalTests();
            $this->validateTestSuite($testSuite);

            $executionResults = $this->executeTestSuite($testSuite);
            $coverage = $this->verifyCoverage($executionResults);
            
            $this->validateResults($executionResults);
            $this->enforceMinimumCoverage($coverage);

            $result = new TestResult([
                'executionId' => $executionId,
                'results' => $executionResults,
                'coverage' => $coverage,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (TestExecutionException $e) {
            DB::rollBack();
            $this->handleTestFailure($e, $executionId);
            throw new CriticalTestException($e->getMessage(), $e);
        }
    }

    private function validateTestSuite(TestSuite $suite): void
    {
        $validationResult = $this->validationChain->validate($suite);
        
        if (!$validationResult->isPassed()) {
            $this->emergency->handleTestSuiteValidationFailure($validationResult);
            throw new InvalidTestSuiteException('Test suite validation failed');
        }
    }

    private function enforceMinimumCoverage(Coverage $coverage): void
    {
        if ($coverage->getPercentage() < 100.0) {
            $this->emergency->handleInsufficientCoverage($coverage);
            throw new InsufficientCoverageException('100% coverage requirement not met');
        }
    }

    private function handleTestFailure(TestExecutionException $e, string $executionId): void
    {
        $this->logger->logFailure($e, $executionId);
        
        if ($e->isCritical()) {
            $this->emergency->handleCriticalTestFailure([
                'exception' => $e,
                'executionId' => $executionId,
                'timestamp' => now()
            ]);
        }
    }
}
