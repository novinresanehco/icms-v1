<?php

namespace App\Core\Operations;

abstract class CriticalOperation implements OperationInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $audit;
    protected MetricsCollector $metrics;

    abstract public function execute(): Result;
    abstract public function validate(): bool;
    abstract public function getRequiredPermissions(): array;
}

class ContentCreateOperation extends CriticalOperation
{
    private array $data;
    private ContentRepository $repository;

    public function execute(): Result
    {
        $this->validateData();
        return $this->repository->create($this->data);
    }

    public function validate(): bool
    {
        return $this->validator->validate($this->data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'boolean',
            'category_id' => 'required|exists:categories,id'
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.create'];
    }

    private function validateData(): void
    {
        if (!$this->validate()) {
            throw new ValidationException('Invalid content data');
        }
    }
}

class ContentUpdateOperation extends CriticalOperation
{
    private string $id;
    private array $data;
    private ContentRepository $repository;

    public function execute(): Result
    {
        $content = $this->repository->findOrFail($this->id);
        $this->validateData();
        return $this->repository->update($content, $this->data);
    }

    public function validate(): bool
    {
        return $this->validator->validate($this->data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'boolean',
            'category_id' => 'exists:categories,id'
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.update'];
    }

    private function validateData(): void
    {
        if (!$this->validate()) {
            throw new ValidationException('Invalid content data');
        }
    }
}

class ContentDeleteOperation extends CriticalOperation
{
    private string $id;
    private ContentRepository $repository;

    public function execute(): Result
    {
        $content = $this->repository->findOrFail($this->id);
        return $this->repository->delete($content);
    }

    public function validate(): bool
    {
        return $this->validator->validate(['id' => $this->id], [
            'id' => 'required|exists:contents,id'
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.delete'];
    }
}

class ContentPublishOperation extends CriticalOperation
{
    private string $id;
    private ContentRepository $repository; 

    public function execute(): Result
    {
        $content = $this->repository->findOrFail($this->id);
        
        if ($content->isPublished()) {
            throw new InvalidOperationException('Content already published');
        }

        return $this->repository->publish($content);
    }

    public function validate(): bool
    {
        return $this->validator->validate(['id' => $this->id], [
            'id' => 'required|exists:contents,id'
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.publish'];
    }
}

class BatchOperationProcessor implements BatchProcessorInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function processBatch(array $operations): array
    {
        $results = [];
        $batchId = uniqid('batch_', true);
        
        $this->metrics->startBatch($batchId);
        
        DB::beginTransaction();
        
        try {
            foreach ($operations as $operation) {
                $results[] = $this->security->executeCriticalOperation($operation);
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BatchProcessingException('Batch processing failed', 0, $e);
            
        } finally {
            $this->metrics->endBatch($batchId);
        }
        
        return $results;
    }
}