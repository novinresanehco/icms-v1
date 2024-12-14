<?php

namespace App\Core\Template\ErrorHandling;

class TemplateErrorHandler
{
    protected LoggerInterface $logger;
    protected array $errorStack = [];
    protected bool $debugMode;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->debugMode = config('app.debug', false);
    }
    
    /**
     * Handle template error
     */
    public function handleError(\Throwable $error, string $template): void
    {
        $context = $this->buildErrorContext($error, $template);
        
        // Log error
        $this->logger->error($error->getMessage(), $context);
        
        // Store error in stack
        $this->errorStack[] = [
            'message' => $error->getMessage(),
            'template' => $template,
            'line' => $error->getLine(),
            'trace' => $this->debugMode ? $error->getTraceAsString() : null
        ];
        
        if ($this->debugMode) {
            throw $error;
        }
        
        // Render fallback template
        return $this->renderFallback($template, $context);
    }
    
    /**
     * Build error context
     */
    protected function buildErrorContext(\Throwable $error, string $template): array
    {
        return [
            'template' => $template,
            'line' => $error->getLine(),
            'file' => $error->getFile(),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'trace' => $error->getTraceAsString(),
            'previous' => $error->getPrevious() ? [
                'message' => $error->getPrevious()->getMessage(),
                'code' => $error->getPrevious()->getCode()
            ] : null
        ];
    }
    
    /**
     * Render fallback template
     */
    protected function renderFallback(string $template, array $context): string
    {
        try {
            return view('errors.template', [
                'template' => $template,
                'context' => $context
            ])->render();
        } catch (\Exception $e) {
            // If fallback fails, return generic error message
            return 'An error occurred while processing the template.';
        }
    }
}

namespace App\Core\Template\Testing;

class TemplateTestSuite
{
    protected TemplateManager $templateManager;
    protected array $assertions = [];
    protected array $results = [];
    
    /**
     * Test template rendering
     */
    public function testTemplate(string $template, array $data = []): TestResult
    {
        try {
            $rendered = $this->templateManager->render($template, $data);
            
            foreach ($this->assertions as $assertion) {
                $result = $assertion->assert($rendered, $data);
                $this->results[] = $result;
                
                if (!$result->passed()) {
                    throw new TemplateTestException($result->getMessage());
                }
            }
            
            return new TestResult(true, 'Template test passed successfully');
        } catch (\Exception $e) {
            return new TestResult(false, $e->getMessage());
        }
    }
    
    /**
     * Add test assertion
     */
    public function addAssertion(TemplateAssertion $assertion): self
    {
        $this->assertions[] = $assertion;
        return $this;
    }
    
    /**
     * Get test results
     */
    public function getResults(): array
    {
        return $this->results;
    }
}

namespace App\Core\Template\Testing\Assertions;

class TemplateAssertion
{
    protected string $name;
    protected $callback;
    protected string $message;
    
    /**
     * Assert template condition
     */
    public function assert(string $rendered, array $data): AssertionResult
    {
        try {
            $result = call_user_func($this->callback, $rendered, $data);
            
            return new AssertionResult(
                $result,
                $result ? 'Assertion passed' : $this->message
            );
        } catch (\Exception $e) {
            return new AssertionResult(false, $e->getMessage());
        }
    }
    
    /**
     * Create contains assertion
     */
    public static function contains(string $needle): self
    {
        return new static(
            'contains',
            fn($rendered) => str_contains($rendered, $needle),
            "Expected template to contain '{$needle}'"
        );
    }
    
    /**
     * Create structure assertion
     */
    public static function hasStructure(array $structure): self
    {
        return new static(
            'structure',
            function($rendered) use ($structure) {
                $dom = new \DOMDocument();
                @$dom->loadHTML($rendered, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                
                foreach ($structure as $selector => $count) {
                    $xpath = new \DOMXPath($dom);
                    $elements = $xpath->query($selector);
                    
                    if ($elements->length !== $count) {
                        throw new TemplateTestException(
                            "Expected {$count} elements matching '{$selector}', found {$elements->length}"
                        );
                    }
                }
                
                return true;
            },
            'Template structure assertion failed'
        );
    }
}

namespace App\Core\Template\Testing\Assertions;

class SecurityAssertion extends TemplateAssertion
{
    /**
     * Assert XSS protection
     */
    public static function isXssSafe(): self
    {
        return new static(
            'xss_safe',
            function($rendered) {
                $dangerous = [
                    '<script>',
                    'javascript:',
                    'onerror=',
                    'onload=',
                    'onclick='
                ];
                
                foreach ($dangerous as $pattern) {
                    if (stripos($rendered, $pattern) !== false) {
                        throw new SecurityAssertionException(
                            "Found potentially unsafe content: {$pattern}"
                        );
                    }
                }
                
                return true;
            },
            'Template contains potentially unsafe content'
        );
    }
    
    /**
     * Assert proper HTML encoding
     */
    public static function hasProperEncoding(): self
    {
        return new static(
            'proper_encoding',
            function($rendered) {
                $testStrings = [
                    '<' => '&lt;',
                    '>' => '&gt;',
                    '"' => '&quot;',
                    "'" => '&#039;'
                ];
                
                foreach ($testStrings as $unsafe => $safe) {
                    if (strpos($rendered, $unsafe) !== false) {
                        throw new SecurityAssertionException(
                            "Found unencoded character: {$unsafe}"
                        );
                    }
                }
                
                return true;
            },
            'Template contains unencoded characters'
        );
    }
}

namespace App\Core\Template\Testing;

class PerformanceTestSuite
{
    protected TemplateManager $templateManager;
    protected array $metrics = [];
    
    /**
     * Run performance test
     */
    public function testPerformance(string $template, array $data = [], int $iterations = 100): PerformanceResult
    {
        $times = [];
        $memory = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $memStart = memory_get_usage();
            
            $this->templateManager->render($template, $data);
            
            $times[] = microtime(true) - $start;
            $memory[] = memory_get_usage() - $memStart;
        }
        
        return new PerformanceResult([
            'average_time' => array_sum($times) / count($times),
            'max_time' => max($times),
            'min_time' => min($times),
            'average_memory' => array_sum($memory) / count($memory),
            'max_memory' => max($memory),
            'iterations' => $iterations
        ]);
    }
}
