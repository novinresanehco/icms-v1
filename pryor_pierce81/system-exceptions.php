<?php
namespace App\Core\Exceptions;

class SystemFailureException extends \Exception {
    protected $context;
    
    public function __construct(string $message, ?\Exception $previous = null, array $context = []) {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }
    
    public function getContext(): array {
        return $this->context;
    }
}

class SecurityException extends SystemFailureException {}
class ValidationException extends SystemFailureException {}
class ContentNotFoundException extends SystemFailureException {}
class SystemOverloadException extends SystemFailureException {}
class CacheException extends SystemFailureException {}
class DatabaseException extends SystemFailureException {}
class ConfigurationException extends SystemFailureException {}
class AuditLoggingException extends SystemFailureException {}
class MetricsException extends SystemFailureException {}

interface ExceptionHandlerInterface {
    public function handle(\Exception $e): void;
    public function report(\Exception $e): void;
    public function shouldReport(\Exception $e): bool;
}

class CriticalExceptionHandler implements ExceptionHandlerInterface {
    private $logger;
    private $alertSystem;
    
    public function handle(\Exception $e): void {
        if ($this->shouldReport($e)) {
            $this->report($e);
        }
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityException($e);
        } elseif ($e instanceof SystemOverloadException) {
            $this->handleSystemOverload($e);
        }
        
        throw $e;
    }
    
    public function report(\Exception $e): void {
        $this->logger->critical($e->getMessage(), [
            'exception' => $e,
            'context' => $e instanceof SystemFailureException ? $e->getContext() : [],
            'stack_trace' => $e->getTraceAsString()
        ]);
        
        $this->alertSystem->notifyError($e);
    }
    
    public function shouldReport(\Exception $e): bool {
        return true;
    }
    
    private function handleSecurityException(SecurityException $e): void {
        // Implement security exception handling
    }
    
    private function handleSystemOverload(SystemOverloadException $e): void {
        // Implement system overload handling
    }
}
