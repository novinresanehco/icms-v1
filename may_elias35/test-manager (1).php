<?php

namespace App\Core\Testing;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Exceptions\TestException;

class TestManager implements TestInterface 
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private array $config;
    private array $activeTests = [];

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function runTestSuite(string $suite): TestResult
    {
        $monitoringId = $this->monitor->startOperation('test_suite_execution');
        
        try {
            $this->validateTestSuite($suite);
            
            $suiteConfig = $this->loadSuiteConfiguration($suite);
            $results = $this->executeTests($suiteConfig['tests']);
            
            $report = $this->generateTestReport($results);
            
            $this->monitor->recordSuccess($monitoringId);
            return $report;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new TestException('Test suite execution failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function runCriticalTests(): TestResult
    {
        $monitoringId = $this->monitor->startOperation('critical_test_execution');
        
        try {
            $criticalTests = $this->getCriticalTests();
            $results = $this->executeTests($criticalTests, true);
            
            if (!$this->validateCriticalResults($results)) {
                throw new TestException('Critical tests failed');
            }
            
            $report = $this->generateTestReport($results);
            
            $this->monitor->recordSuccess($monitoringId);
            return $report;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new TestException('Critical test execution failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function validateSystem(): bool
    {
        $monitoringId = $this->monitor->startOperation('system_validation');
        
        try {
            $validationTests = $this->getValidationTests();
            $results = $this->executeTests($validationTests);
            
            $isValid = $this->validateResults($results);
            
            if ($isValid) {
                $this->monitor->recordSuccess($monitoringId);
            }
            
            return $isValid;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new TestException('System validation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateTestSuite(string $suite): void
    {
        if (!in_array($suite, $this->config['available_suites'])) {
            throw new TestException('Invalid test suite');
        }
    }

    private function loadSuiteConfiguration(string $suite): array
    {
        $config = $this->config['suites'][$suite] ?? null;
        
        if (!$config) {
            throw new TestException('Test suite configuration not found');
        }
        
        return $config;
    }

    private function executeTests(array $tests, bool $critical = false): array
    {
        $results = [];
        
        foreach ($tests as $test) {
            try {
                $result = $this->executeTest($test, $critical);
                $results[$test] = $result;
                
                if ($critical && !$result->isSuccess()) {
                    break;
                }
            } catch (\Exception $e) {
                $results[$test] = new TestResult(false, $e->getMessage());
                
                if ($critical) {
                    break;
                }
            }
        }
        
        return $results;
    }

    private function executeTest(string $test, bool $critical): TestResult
    {
        $testId = $this->initializeTest($test);
        
        try {
            $this->validateTestExecution($test);
            
            $instance = $this->createTestInstance($test);
            $result = $instance->execute();
            
            $this->validateTestResult($result, $critical);
            $this->recordTestExecution($testId, $result);
            
            return $result;
            
        } finally {
            $this->finalizeTest($testId);
        }
    }

    private function generateTestReport(array $results): TestResult
    {
        $success = !in_array(false, array_map(function($result) {
            return $result->isSuccess();
        }, $results));

        return new TestResult(
            $success,
            $this->formatTestResults($results)
        );
    }

    private function getCriticalTests(): array
    {
        return $this->config['critical_tests'];
    }

    private function getValidationTests(): array
    {
        return $this->config['validation_tests'];
    }

    private function validateCriticalResults(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->isSuccess()) {
                return false;
            }
        }
        return true;
    }

    private function validateResults(array $results): bool
    {
        $failureThreshold = $this->config['failure_threshold'];
        $failureCount = 0;
        
        foreach ($results as $result) {
            if (!$result->isSuccess()) {
                $failureCount++;
            }
            
            if ($failureCount > $failureThreshold) {
                return false;
            }
        }
        
        return true;
    }

    private function initializeTest(string $test): string
    {
        $testId = $this->generateTestId();
        
        $this->activeTests[$testId] = [
            'test' => $test,
            'started_at' => microtime(true),
            'status' => 'running'
        ];
        
        return $testId;
    }

    private function validateTestExecution(string $test): void
    {
        if (!class_exists($test)) {
            throw new TestException("Test class not found: {$test}");
        }

        if (!is_subclass_of($test, TestCase::class)) {
            throw new TestException("Invalid test class: {$test}");
        }
    }

    private function createTestInstance(string $test): TestCase
    {
        return new $test($this->security, $this->monitor);
    }

    private function validateTestResult(TestResult $result, bool $critical): void
    {
        if ($critical && !$result->isSuccess()) {
            throw new TestException('Critical test failed: ' . $result->getMessage());
        }
    }

    private function recordTestExecution(string $testId, TestResult $result): void
    {
        TestExecution::create([
            'test_id' => $testId,
            'test' => $this->activeTests[$testId]['test'],
            'result' => $result->isSuccess(),
            'message' => $result->getMessage(),
            'execution_time' => microtime(true) - $this->activeTests[$testId]['started_at'],
            'executed_at' => now()
        ]);
    }

    private function finalizeTest(string $testId): void
    {
        unset($this->activeTests[$testId]);
    }

    private function generateTestId(): string
    {
        return uniqid('test_', true);
    }

    private function formatTestResults(array $results): string
    {
        $summary = [];
        
        foreach ($results as $test => $result) {
            $summary[] = sprintf(
                "%s: %s - %s",
                $test,
                $result->isSuccess() ? 'PASS' : 'FAIL',
                $result->getMessage()
            );
        }
        
        return implode("\n", $summary);
    }
}
