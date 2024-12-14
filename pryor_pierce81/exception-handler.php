<?php

namespace App\Core\Exception;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Audit\AuditManagerInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;

class ExceptionManager implements ExceptionManagerInterface
{
    private SecurityManagerInterface $security;
    private AuditManagerInterface $audit;
    private LoggerInterface $logger;
    private array $config;
    private array $activeIncidents = [];

    public function __construct(
        SecurityManagerInterface $security,
        AuditManagerInterface $audit,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->audit = $audit;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function handleException(\Throwable $e, array $context = []): string
    {
        $incidentId = $this->generateIncidentId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('exception:handle', [
                'incident_id' => $incidentId
            ]);

            $this->logException($incidentId, $e, $context);
            $this->processException($incidentId, $e, $context);
            
            if ($this->isCriticalException($e)) {
                $this->handleCriticalException($incidentId, $e, $context);
            }

            DB::commit();
            return $incidentId;

        } catch (\Exception $secondary) {
            DB::rollBack();
            $this->handleSystemFailure($incidentId, $e, $secondary);
            throw new SystemFailureException('Exception handling failed', 0, $secondary);
        }
    }

    public function trackException(string $incidentId): IncidentStatus
    {
        try {
            $this->security->validateSecureOperation('exception:track', [
                'incident_id' => $incidentId
            ]);

            return $this->getIncidentStatus($incidentId);

        } catch (\Exception $e) {
            $this->handleSystemFailure($incidentId, null, $e);
            throw new SystemFailureException('Exception tracking failed', 0, $e);
        }
    }

    protected function logException(string $incidentId, \Throwable $e, array $context): void
    {
        $data = [
            'incident_id' => $incidentId,
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'context' => json_encode($context),
            'timestamp' => now(),
            'severity' => $this->determineSeverity($e)
        ];

        DB::table('exception_log')->insert($data);
        
        $this->logger->error('Exception occurred', $data);
    }

    protected function processException(string $incidentId, \Throwable $e, array $context): void
    {
        $incident = [
            'id' => $incidentId,
            'status' => IncidentStatus::ACTIVE,
            'started_at' => now(),
            'last_updated' => now()
        ];

        $this->activeIncidents[$incidentId] = $incident;

        $this->audit->logSecurityEvent([
            'event_type' => 'exception',
            'severity' => $this->determineSeverity($e),
            'details' => [
                'incident_id' => $incidentId,
                'exception_type' => get_class($e),
                'message' => $e->getMessage()
            ]
        ]);
    }

    protected function handleCriticalException(string $incidentId, \Throwable $e, array $context): void
    {
        $this->notifyCriticalException($incidentId, $e);
        $this->initiateEmergencyProcedures($incidentId, $e);
        $this->documentCriticalIncident($incidentId, $e, $context);
    }

    protected function handleSystemFailure(string $incidentId, ?\Throwable $primary, \Throwable $secondary): void
    {
        try {
            $this->logger->critical('System failure during exception handling', [
                'incident_id' => $incidentId,
                'primary_exception' => $primary ? [
                    'type' => get_class($primary),
                    'message' => $primary->getMessage()
                ] : null,
                'secondary_exception' => [
                    'type' => get_class($secondary),
                    'message' => $secondary->getMessage()
                ]
            ]);

            $this->notifySystemFailure($incidentId, $primary, $secondary);

        } catch (\Exception $e) {
            // Last resort logging
            error_log('Critical system failure: ' . $e->getMessage());
        }
    }

    protected function determineSeverity(\Throwable $e): string
    {
        if ($e instanceof SecurityException) {
            return 'critical';
        }

        if ($e instanceof