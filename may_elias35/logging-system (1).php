<?php

namespace App\Core\Logging\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    protected $fillable = [
        'level',
        'message',
        'context',
        'stack_trace',
        'user_id',
        'ip_address',
        'user_agent',
        'url',
        'method'
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime'
    ];
}

namespace App\Core\Logging\Services;

class LogManager
{
    private SystemLogger $systemLogger;
    private AuditLogger $auditLogger;
    private LogRotator $logRotator;

    public function __construct(
        SystemLogger $systemLogger,
        AuditLogger $auditLogger,
        LogRotator $logRotator
    ) {
        $this->systemLogger = $systemLogger;
        $this->auditLogger = $auditLogger;
        $this->logRotator = $logRotator;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->systemLogger->log($level, $message, $context);
    }

    public function audit(string $action, $model, array $oldValues = [], array $newValues = []): void
    {
        $this->auditLogger->log($action, $model, $oldValues, $newValues);
    }

    public function rotate(): void
    {
        $this->logRotator->rotate();
    }
}

class SystemLogger
{
    public function log(string $level, string $message, array $context = []): void
    {
        SystemLog::create([
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'stack_trace' => $this->getStackTrace(),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
            'method' => request()->method()
        ]);
    }

    private function getStackTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        return json_encode(array_slice($trace, 2));
    }
}

class AuditLogger
{
    public function log(string $action, $model, array $oldValues = [], array $newValues = []): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }
}

class LogRotator
{
    private $maxAge;
    private $chunkSize;

    public function __construct(int $maxAge = 30, int $chunkSize = 1000)
    {
        $this->maxAge = $maxAge;
        $this->chunkSize = $chunkSize;
    }

    public function rotate(): void
    {
        $this->rotateSystemLogs();
        $this->rotateAuditLogs();
    }

    private function rotateSystemLogs(): void
    {
        SystemLog::where('created_at', '<', now()->subDays($this->maxAge))
            ->chunkById($this->chunkSize, function ($logs) {
                foreach ($logs as $log) {
                    $log->delete();
                }
            });
    }

    private function rotateAuditLogs(): void
    {
        AuditLog::where('created_at', '<', now()->subDays($this->maxAge))
            ->chunkById($this->chunkSize, function ($logs) {
                foreach ($logs as $log) {
                    $log->delete();
                }
            });
    }
}

namespace App\Core\Logging\Traits;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(LogManager::class)->audit('created', $model, [], $model->getAttributes());
        });

        static::updated(function ($model) {
            app(LogManager::class)->audit(
                'updated',
                $model,
                $model->getOriginal(),
                $model->getChanges()
            );
        });

        static::deleted(function ($model) {
            app(LogManager::class)->audit('deleted', $model, $model->getAttributes(), []);
        });
    }
}

namespace App\Core\Logging\Http\Controllers;

use App\Core\Logging\Services\LogManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    private LogManager $logManager;

    public function __construct(LogManager $logManager)
    {
        $this->logManager = $logManager;
    }

    public function system(Request $request): JsonResponse
    {
        $logs = SystemLog::query()
            ->when($request->level, fn($q) => $q->where('level', $request->level))
            ->when($request->search, fn($q) => $q->where('message', 'like', "%{$request->search}%"))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($logs);
    }

    public function audit(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->when($request->action, fn($q) => $q->where('action', $request->action))
            ->when($request->model_type, fn($q) => $q->where('model_type', $request->model_type))
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($logs);
    }

    public function rotate(): JsonResponse
    {
        try {
            $this->logManager->rotate();
            return response()->json(['message' => 'Logs rotated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Logging\Console;

use Illuminate\Console\Command;

class RotateLogsCommand extends Command
{
    protected $signature = 'logs:rotate';
    protected $description = 'Rotate system and audit logs';

    public function handle(LogManager $logManager): void
    {
        $this->info('Rotating logs...');
        $logManager->rotate();
        $this->info('Logs rotated successfully');
    }
}
