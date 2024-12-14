<?php

namespace App\Services;

class ContentService implements CriticalServiceInterface
{
    private SecurityService $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private ContentRepository $repository;
    private CacheManager $cache;
    private EventDispatcher $events;

    public function createContent(array $data, SecurityContext $context): ServiceResult
    {
        $this->monitor->startOperation('content_create');
        DB::beginTransaction();

        try {
            // Multi-layer validation
            $this->security->validateContext($context);
            $this->security->validatePermissions($context, 'content.create');
            $validatedData = $this->validator->validateContent($data);

            // Protected execution
            $content = $this->executeProtected(function() use ($validatedData, $context) {
                $content = $this->repository->create($validatedData);
                $this->cache->invalidate(['content', $content->getId()]);
                $this->events->dispatch(new ContentCreated($content, $context));
                return $content;
            });

            // Post-creation verification
            $this->verifyCreation($content);
            
            DB::commit();
            return new ServiceResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation();
        }
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ServiceResult
    {
        $this->monitor->startOperation('content_update');
        DB::beginTransaction();

        try {
            // Security validation
            $this->security->validateContext($context);
            $this->security->validatePermissions($context, 'content.update', $id);
            $validatedData = $this->validator->validateContent($data);

            // Protected execution
            $content = $this->executeProtected(function() use ($id, $validatedData, $context) {
                $content = $this->repository->update($id, $validatedData);
                $this->cache->invalidate(['content', $id]);
                $this->events->dispatch(new ContentUpdated($content, $context));
                return $content;
            });

            // Post-update verification
            $this->verifyUpdate($content);

            DB::commit();
            return new ServiceResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation();
        }
    }

    public function getContent(int $id, SecurityContext $context): ServiceResult
    {
        $this->monitor->startOperation('content_get');

        try {
            // Security checks
            $this->security->validateContext($context);
            $this->security->validatePermissions($context, 'content.read', $id);

            // Protected retrieval
            $content = $this->cache->remember(['content', $id], function() use ($id, $context) {
                return $this->repository->findWithSecurity($id, $context);
            });

            // Verify retrieval
            $this->verifyRetrieval($content);

            return new ServiceResult($content);

        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation();
        }
    }

    private function executeProtected(callable $operation)
    {
        return $this->monitor->track(function() use ($operation) {
            return $operation();
        });
    }

    private function verifyCreation(Content $content): void
    {
        if (!$content->isValid()) {
            throw new ValidationException('Content creation validation failed');
        }

        if (!$this->security->verifyContentSecurity($content)) {
            throw new SecurityException('Content security verification failed');
        }
    }

    private function verifyUpdate(Content $content): void
    {
        if (!$content->isValid()) {
            throw new ValidationException('Content update validation failed');
        }

        if (!$this->security->verifyContentSecurity($content)) {
            throw new SecurityException('Content security verification failed');
        }
    }

    private function verifyRetrieval(Content $content): void
    {
        if (!$content) {
            throw new NotFoundException('Content not found');
        }

        if (!$this->security->verifyContentSecurity($content)) {
            throw new SecurityException('Content security verification failed');
        }
    }

    private function handleFailure(\Exception $e): void
    {
        if ($e instanceof SecurityException) {
            $this->monitor->logSecurityIncident($e);
            $this->security->handleSecurityFailure($e);
        } elseif ($e instanceof ValidationException) {
            $this->monitor->logValidationFailure($e);
        } else {
            $this->monitor->logSystemFailure($e);
        }

        $this->events->dispatch(new OperationFailed($e));
    }
}

interface CriticalServiceInterface
{
    public function createContent(array $data, SecurityContext $context): ServiceResult;
    public function updateContent(int $id, array $data, SecurityContext $context): ServiceResult;
    public function getContent(int $id, SecurityContext $context): ServiceResult;
}

class ServiceResult
{
    private $data;
    private array $metrics;

    public function __construct($data)
    {
        $this->data = $data;
        $this->metrics = [
            'timestamp' => microtime(true),
            'memory' => memory_get_peak_usage(true),
            'cpu' => sys_getloadavg()[0]
        ];
    }

    public function getData()
    {
        return $this->data;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
