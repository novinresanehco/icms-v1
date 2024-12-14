namespace App\Core\Audit;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Log, Event, Storage};
use Monolog\Logger;
use Monolog\Handler\{StreamHandler, RotatingFileHandler};
use Monolog\Formatter\JsonFormatter;

class AuditLoggingService implements AuditLoggerInterface
{
    private SecurityManager $security;
    private Logger $logger;
    private LogEncoder $encoder;
    private LogArchiver $archiver;
    private LogValidator $validator;
    private AuditConfig $config;

    private const LOG_LEVELS = [
        'emergency' => Logger::EMERGENCY,
        'alert'     => Logger::ALERT,
        'critical'  => Logger::CRITICAL,
        'error'     => Logger::ERROR,
        'warning'   => Logger::WARNING,
        'notice'    => Logger::NOTICE,
        'info'      => Logger::INFO,
        'debug'     => Logger::DEBUG,
    ];

    public function __construct(
        SecurityManager $security,
        LogEncoder $encoder,
        LogArchiver $archiver,
        LogValidator $validator,
        AuditConfig $config
    ) {
        $this->security = $security;
        $this->encoder = $encoder;
        $this->archiver = $archiver;
        $this->validator = $validator;
        $this->config = $config;
        
        $this->initializeLogger();
    }

    public function logSecurityEvent(string $event, array $data, string $level = 'info'): void
    {
        $this->security->executeCriticalOperation(
            new LogSecurityEventOperation($event, $data),
            new SecurityContext(['log_type' => 'security']),
            function() use ($event, $data, $level) {
                $validated = $this->validator->validateSecurityEvent($data);
                $encoded = $this->encoder->encodeSecurityEvent($event, $validated);
                
                $this->writeSecurityLog($encoded, $level);
                $this->notifySecurityEvent($event, $validated);
                
                if ($this->isHighSeverityEvent($level)) {
                    $this->handleHighSeverityEvent($event, $validated);
                }
            }
        );
    }

    public function logAuditEvent(string $event, array $data): void
    {
        $this->security->executeCriticalOperation(
            new LogAuditEventOperation($event, $data),
            new SecurityContext(['log_type' => 'audit']),
            function() use ($event, $data) {
                $validated = $this->validator->validateAuditEvent($data);
                $encoded = $this->encoder->encodeAuditEvent($event, $validated);
                
                $this->writeAuditLog($encoded);
                $this->archiveAuditEvent($encoded);
                
                Event::dispatch(new AuditEventLogged($event, $validated));
            }
        );
    }

    public function logSystemEvent(string $event, array $data, string $level = 'info'): void
    {
        $this->security->executeCriticalOperation(
            new LogSystemEventOperation($event, $data),
            new SecurityContext(['log_type' => 'system']),
            function() use ($event, $data, $level) {
                $validated = $this->validator->validateSystemEvent($data);
                $encoded = $this->encoder->encodeSystemEvent($event, $validated);
                
                $this->writeSystemLog($encoded, $level);
                
                if ($this->isSignificantSystemEvent($event)) {
                    $this->handleSignificantSystemEvent($event, $validated);
                }
            }
        );
    }

    public function logOperationEvent(string $operation, array $data, string $result): void
    {
        $this->security->executeCriticalOperation(
            new LogOperationEventOperation($operation, $data),
            new SecurityContext(['log_type' => 'operation']),
            function() use ($operation, $data, $result) {
                $validated = $this->validator->validateOperationEvent($data);
                $encoded = $this->encoder->encodeOperationEvent($operation, $validated, $result);
                
                $this->writeOperationLog($encoded);
                
                if ($result === 'failure') {
                    $this->handleFailedOperation($operation, $validated);
                }
            }
        );
    }

    private function initializeLogger(): void
    {
        $this->logger = new Logger('audit');
        
        // Add handlers based on configuration
        foreach ($this->config->getLogHandlers() as $handler) {
            $this->logger->pushHandler($this->createHandler($handler));
        }
        
        // Add processors for additional context
        $this->logger->pushProcessor([$this, 'addSecurityContext']);
        $this->logger->pushProcessor([$this, 'addSystemContext']);
    }

    private function createHandler(array $config): \Monolog\Handler\HandlerInterface
    {
        return match ($config['type']) {
            'stream' => new StreamHandler(
                $config['path'],
                self::LOG_LEVELS[$config['level']],
                true,
                0777
            ),
            'rotating' => new RotatingFileHandler(
                $config['path'],
                $config['max_files'],
                self::LOG_LEVELS[$config['level']]
            ),
            default => throw new \InvalidArgumentException("Unknown handler type: {$config['type']}")
        };
    }

    public function addSecurityContext(array $record): array
    {
        $record['extra']['ip'] = request()->ip();
        $record['extra']['user_id'] = auth()->id();
        $record['extra']['session_id'] = session()->getId();
        
        return $record;
    }

    public function addSystemContext(array $record): array
    {
        $record['extra']['memory_usage'] = memory_get_peak_usage(true);
        $record['extra']['system_load'] = sys_getloadavg();
        
        return $record;
    }

    private function writeSecurityLog(array $data, string $level): void
    {
        $this->logger->log(
            self::LOG_LEVELS[$level],
            'Security Event',
            $this->prepareLogData($data)
        );
    }

    private function writeAuditLog(array $data): void
    {
        $this->logger->info(
            'Audit Event',
            $this->prepareLogData($data)
        );
        
        $this->archiver->archiveAuditLog($data);
    }

    private function writeSystemLog(array $data, string $level): void
    {
        $this->logger->log(
            self::LOG_LEVELS[$level],
            'System Event',
            $this->prepareLogData($data)
        );
    }

    private function writeOperationLog(array $data): void
    {
        $this->logger->info(
            'Operation Event',
            $this->prepareLogData($data)
        );
    }

    private function prepareLogData(array $data): array
    {
        return array_merge($data, [
            'timestamp' => time(),
            'environment' => app()->environment(),
            'version' => config('app.version')
        ]);
    }

    private function isHighSeverityEvent(string $level): bool
    {
        return in_array($level, ['emergency', 'alert', 'critical']);
    }

    private function isSignificantSystemEvent(string $event): bool
    {
        return in_array($event, $this->config->getSignificantSystemEvents());
    }

    private function handleHighSeverityEvent(string $event, array $data): void
    {
        Event::dispatch(new HighSeverityEventDetected($event, $data));
        
        if ($this->config->isAlertingEnabled()) {
            $this->sendAlerts($event, $data);
        }
    }

    private function handleSignificantSystemEvent(string $event, array $data): void
    {
        Event::dispatch(new SignificantSystemEventDetected($event, $data));
        
        if ($this->config->requiresImmedidateAction($event)) {
            $this->initiateEmergencyProcedures($event, $data);
        }
    }

    private function handleFailedOperation(string $operation, array $data): void
    {
        Event::dispatch(new OperationFailureDetected($operation, $data));
        
        if ($this->isRecoverableOperation($operation)) {
            $this->initiateOperationRecovery($operation, $data);
        }
    }
}
