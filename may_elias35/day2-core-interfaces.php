<?php
namespace App\Core\Contracts;

interface SecurityManagerInterface
{
    public function validateOperation(Operation $op): Result;
    public function logSecurityEvent(string $event, array $context): void;
}

interface ContentManagerInterface  
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function get(int $id): Content;
}

interface ValidationServiceInterface
{
    public function validate(array $data): array;
    public function validateFile(UploadedFile $file): bool;
}

interface MonitoringInterface
{
    public function monitor(): SystemStatus;
    public function handleCritical(Metrics $metrics): void;
}
