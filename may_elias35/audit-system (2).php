```php
namespace App\Core\Audit;

class AuditLogger implements AuditInterface
{
    private LogManager $logger;
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function logPermissionCheck(User $user, string $permission, bool $result): void
    {
        $this->logger->info('permission_check', [
            'user_id' => $user->id,
            'permission' => $permission,
            'result' => $result,
            'timestamp' => now(),
            'ip' => request()->ip()
        ]);

        $this->metrics->increment(
            $result ? 'permission.granted' : 'permission.denied'
        );
    }

    public function logAccessAttempt(array $context, bool $result): void
    {
        $this->logger->info('access_attempt', [
            'user_id' => $context['user']->id,
            'resource' => $context['resource'],
            'action' => $context['action'],
            'result' => $result,
            'timestamp' => now(),
            'ip' => request()->ip()
        ]);

        $this->metrics->increment(
            $result ? 'access.granted' : 'access.denied'
        );
    }
}
```
