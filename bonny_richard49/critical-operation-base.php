<?php

namespace App\Core\Operations;

use App\Core\Security\SecurityContext;
use App\Core\Security\CoreSecurityManager;
use App\Core\Monitoring\OperationMonitor;

abstract class CriticalOperation
{
    protected CoreSecurityManager $security;
    protected OperationMonitor $monitor;

    public function __construct(
        CoreSecurityManager $security,
        OperationMonitor $monitor
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function execute(array $data): mixed
    {
        // Create security context
        $context = $this->createContext($data);
        
        // Execute with full security controls
        return $this->security->executeSecureOperation(
            function() use ($data) {
                // Start monitoring
                $this->monitor->startOperation();
                
                try {
                    // Execute actual operation
                    $result = $this->doExecute($data);
                    
                    // Validate result
                    $this->validateResult($result);
                    
                    return $result;
                    
                } finally {
                    // Always stop monitoring
                    $this->monitor->stopOperation();
                }
            },
            $context
        );
    }

    /**
     * Create security context for operation
     */
    abstract protected function createContext(array $data): SecurityContext;

    /**
     * Execute the actual operation
     */
    abstract protected function doExecute(array $data): mixed;

    /**
     * Validate operation result
     */
    abstract protected function validateResult(mixed $result): void;
}
