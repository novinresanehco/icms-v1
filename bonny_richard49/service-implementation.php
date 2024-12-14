<?php

namespace App\Services;

class ServiceManager implements ServiceManagerInterface
{
    private ServiceRegistry $registry;
    private SecurityManager $security;
    private MonitoringService $monitor;
    private AuditLogger $logger;

    public function executeService(string $service, array $data): ServiceResult
    {
        try {
            // Validate service
            $this->validateService($service);
            
            // Create context
            $context = $this->createServiceContext($service, $data);
            
            // Execute with monitoring
            return $this->monitor->trackService(
                fn() => $this->registry->get($service)->execute($context),
                $context
            );
            
        } catch (\Exception $e) {
            $this->handleServiceFailure($service, $e);
            throw $e;
        }
    }

    private function validateService(string $service): void
    {
        if (!$this->registry->has($service)) {
            throw new ServiceException('Service not found');
        }

        if (!$this->security->validateService($service)) {
            throw new SecurityException('Service validation failed');
        }
    }

    private function createServiceContext(string $service, array $data): ServiceContext
    {
        return new ServiceContext([
            'service' => $service,
            'data' => $data,
            'security' => $this->security->getCurrentContext(),
            'timestamp' => now()
        ]);
    }

    private function handleServiceFailure(string $service, \Exception $e): void
    {
        $this->logger->logServiceFailure($service, $e);
        $this->monitor->recordServiceFailure($service);
    }
}

abstract class CriticalService implements ServiceInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected MonitoringService $monitor;
    protected AuditLogger $logger;

    abstract public function execute(ServiceContext $context): ServiceResult;
    abstract protected function validateInput(array $data): void;
    abstract protected function executeOperation(array $data): mixed;
    abstract protected function validateResult($result): void;

    protected function executeSecurely(array $data): ServiceResult
    {
        try {
            // Validate input
            $this->validateInput($data);
            
            // Execute main operation
            $result = $this->executeOperation($data);
            
            // Validate result
            $this->validateResult($result);
            
            return new ServiceResult($result);
            
        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    protected function handleFailure(\Exception $e): void
    {
        $this->logger->logFailure(static::class, $e);
        $this->monitor->recordFailure(static::class);
    }
}

class ContentService extends CriticalService
{
    public function execute(ServiceContext $context): ServiceResult
    {
        return $this->executeSecurely($context->getData());
    }

    protected function validateInput(array $data): void
    {
        $rules = [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid content data');
        }
    }

    protected function executeOperation(array $data): Content
    {
        return Content::create($data);
    }

    protected function validateResult($result): void
    {
        if (!$result instanceof Content) {
            throw new ServiceException('Invalid operation result');
        }
    }
}

class AuthenticationService extends CriticalService
{
    public function execute(ServiceContext $context): ServiceResult
    {
        return $this->executeSecurely($context->getData());
    }

    protected function validateInput(array $data): void
    {
        $rules = [
            'email' => 'required|email',
            'password' => 'required|string'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid authentication data');
        }
    }

    protected function executeOperation(array $data): AuthResult
    {
        return $this->security->authenticate($data['email'], $data['password']);
    }

    protected function validateResult($result): void
    {
        if (!$result instanceof AuthResult) {
            throw new ServiceException('Invalid authentication result');
        }
    }
}