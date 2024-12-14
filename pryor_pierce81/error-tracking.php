<?php

namespace App\Core\Error;

class ErrorTracker
{
    protected array $ignoredExceptions = [];
    protected array $criticalExceptions = [];
    protected $notifier;

    public function track(\Throwable $exception): void
    {
        if ($this->shouldIgnore($exception)) {
            return;
        }

        $context = $this->buildContext($exception);
        $this->store($exception, $context);

        if ($this->isCritical($exception)) {
            $this->notifier->notifyCriticalError($exception, $context);
        }
    }

    protected function buildContext(\Throwable $exception): array
    {
        return [
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'user' => auth()->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'session' => $this->sanitizeSession(),
            'inputs' => $this->sanitizeInputs(),
            'trace' => $this->formatTrace($exception)
        ];
    }

    protected function store(\Throwable $exception, array $context): void
    {
        DB::table('error_logs')->insert([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => json_encode($context),
            'created_at' => now()
        ]);
    }

    protected function shouldIgnore(\Throwable $exception): bool
    {
        foreach ($this->ignoredExceptions as $ignored) {
            if ($exception instanceof $ignored) {
                return true;
            }
        }
        return false;
    }

    protected function isCritical(\Throwable $exception): bool
    {
        foreach ($this->criticalExceptions as $critical) {
            if ($exception instanceof $critical) {
                return true;
            }
        }
        return false;
    }

    protected function sanitizeSession(): array
    {
        $session = session()->all();
        unset($session['_token'], $session['password']);
        return $session;
    }

    protected function sanitizeInputs(): array
    {
        $inputs = request()->except(['password', 'password_confirmation']);
        return $inputs;
    }

    protected function formatTrace(\Throwable $exception): array
    {
        return collect($exception->getTrace())
            ->map(function ($trace) {
                return array_only($trace, ['file', 'line', 'function', 'class']);
            })
            ->toArray();
    }
}

class ErrorNotifier
{
    protected array $channels = [];
    protected array $recipients = [];

    public function notifyCriticalError(\Throwable $exception, array $context): void
    {
        foreach ($this->channels as $channel) {
            $this->sendNotification($channel, $exception, $context);
        }
    }

    protected function sendNotification($channel, \Throwable $exception, array $context): void
    {
        $notification = $this->formatNotification($exception, $context);
        
        switch ($channel) {
            case 'email':
                $this->sendEmail($notification);
                break;
            case 'slack':
                $this->sendSlack($notification);
                break;
            case 'telegram':
                $this->sendTelegram($notification);
                break;
        }
    }

    protected function formatNotification(\Throwable $exception, array $context): array
    {
        return [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'environment' => app()->environment(),
            'server' => gethostname(),
            'timestamp' => now()->toIso8601String(),
            'context' => $context
        ];
    }
}

class ErrorAnalyzer
{
    public function analyze(string $timeframe = '24h'): array
    {
        $logs = $this->getLogs($timeframe);
        
        return [
            'total_errors' => $logs->count(),
            'unique_errors' => $logs->unique('exception')->count(),
            'most_frequent' => $this->getMostFrequent($logs),
            'error_timeline' => $this->getTimeline($logs),
            'error_types' => $this->getTypes($logs),
            'affected_users' => $this->getAffectedUsers($logs)
        ];
    }

    protected function getLogs(string $timeframe): Collection
    {
        $since = $this->parseTimeframe($timeframe);
        
        return DB::table('error_logs')
            ->where('created_at', '>=', $since)
            ->get();
    }

    protected function getMostFrequent(Collection $logs): Collection
    {
        return $logs->groupBy('exception')
            ->map(fn($group) => [
                'count' => $group->count(),
                'last_occurrence' => $group->max('created_at'),
                'sample' => $group->first()
            ])
            ->sortByDesc('count')
            ->take(10);
    }

    protected function parseTimeframe(string $timeframe): Carbon
    {
        preg_match('/(\d+)([hdwmy])/', $timeframe, $matches);
        
        switch ($matches[2]) {
            case 'h': return now()->subHours($matches[1]);
            case 'd': return now()->subDays($matches[1]);
            case 'w': return now()->subWeeks($matches[1]);
            case 'm': return now()->subMonths($matches[1]);
            case 'y': return now()->subYears($matches[1]);
            default: return now()->subDay();
        }
    }
}
