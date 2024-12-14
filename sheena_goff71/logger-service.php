<?php

namespace App\Core\Logger\Services;

use App\Core\Logger\Models\Log;
use App\Core\Logger\Repositories\LogRepository;
use Illuminate\Support\Facades\Auth;

class LoggerService
{
    public function __construct(
        private LogRepository $repository,
        private LogValidator $validator,
        private LogFormatter $formatter
    ) {}

    public function log(
        string $type,
        string $message,
        array $context = [],
        string $level = 'info'
    ): Log {
        $this->validator->validate($type, $message, $context, $level);

        return $this->repository->create([
            'type' => $type,
            'message' => $message,
            'context' => $this->formatter->formatContext($context),
            'level' => $level,
            'user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function logException(\Throwable $exception, array $context = []): Log
    {
        return $this->log(
            'exception',
            $exception->getMessage(),
            array_merge([
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ], $context),
            'error'
        );
    }

    public function logActivity(string $action, array $data = []): Log
    {
        return $this->log('activity', $action, $data, 'info');
    }

    public function logSecurity(string $event, array $details = []): Log
    {
        return $this->log('security', $event, $details, 'warning');
    }

    public function logPerformance(string $operation, float $duration, array $metrics = []): Log
    {
        return $this->log('performance', $operation, array_merge([
            'duration' => $duration,
            'memory_usage' => memory_get_peak_usage(true)
        ], $metrics));
    }

    public function search(array $filters = []): Collection
    {
        return $this->repository->getWithFilters($filters);
    }

    public function getStats(array $filters = []): array
    {
        return $this->repository->getStats($filters);
    }

    public function cleanup(int $days = 30): int
    {
        return $this->repository->deleteOlderThan($days);
    }

    public function export(array $filters = []): string
    {
        $logs = $this->search($filters);
        return $this->formatter->formatForExport($logs);
    }
}
