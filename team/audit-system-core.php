namespace App\Core\Audit;

class AuditManager implements AuditInterface 
{
    private LogManager $logger;
    private SecurityManager $security;
    private DatabaseManager $database;
    private AuditVerifier $verifier;
    private AlertSystem $alerts;

    public function __construct(
        LogManager $logger,
        SecurityManager $security,
        DatabaseManager $database,
        AuditVerifier $verifier,
        AlertSystem $alerts
    ) {
        $this->logger = $logger;
        $this->security = $security;
        $this->database = $database;
        $this->verifier = $verifier;
        $this->alerts = $alerts;
    }

    public function logCriticalOperation(OperationContext $context): void 
    {
        DB::beginTransaction();

        try {
            // Create audit record
            $record = $this->createAuditRecord($context);
            
            // Verify record integrity
            $this->verifyAuditRecord($record);
            
            // Store with encryption
            $this->storeAuditRecord($record);
            
            // Verify storage
            $this->verifyStorage($record);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $context);
            throw new AuditException('Audit failure: ' . $e->getMessage(), 0, $e);
        }
    }

    private function createAuditRecord(OperationContext $context): AuditRecord
    {
        return new AuditRecord([
            'operation_id' => $context->getOperationId(),
            'user_id' => $context->getUserId(),
            'action' => $context->getAction(),
            'resource_type' => $context->getResourceType(),
            'resource_id' => $context->getResourceId(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
            'timestamp' => now(),
            'data' => $this->security->encrypt($context->getData()),
            'hash' => $this->generateRecordHash($context)
        ]);
    }

    private function verifyAuditRecord(AuditRecord $record): void
    {
        if (!$this->verifier->verifyRecord($record)) {
            throw new AuditVerificationException('Audit record verification failed');
        }
    }

    private function storeAuditRecord(AuditRecord $record): void
    {
        // Store in database
        $this->database->storeAudit($record);
        
        // Store in secure log
        $this->logger->logAudit($record);
        
        // Send to security monitoring
        $this->security->monitorAudit($record);
    }

    private function verifyStorage(AuditRecord $record): void
    {
        // Verify database storage
        if (!$this->verifier->verifyDatabaseRecord($record)) {
            throw new StorageVerificationException('Database storage verification failed');
        }
        
        // Verify log storage
        if (!$this->verifier->verifyLogRecord($record)) {
            throw new StorageVerificationException('Log storage verification failed');
        }
    }

    private function handleAuditFailure(\Exception $e, OperationContext $context): void
    {
        // Log failure
        $this->logger->emergency('Audit system failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context->toArray()
        ]);

        // Alert security team
        $this->alerts->criticalAuditFailure($e, $context);

        // Attempt recovery
        $this->attemptAuditRecovery($context);
    }

    private function generateRecordHash(OperationContext $context): string
    {
        return hash_hmac(
            'sha256',
            json_encode($context->toArray()),
            $this->security->getAuditKey()
        );
    }

    private function attemptAuditRecovery(OperationContext $context): void
    {
        try {
            // Create emergency audit record
            $emergencyRecord = $this->createEmergencyAuditRecord($context);
            
            // Store in separate secure storage
            $this->storeEmergencyRecord($emergencyRecord);
            
        } catch (\Exception $e) {
            // Log critical failure
            $this->logger->critical('Audit recovery failed', [
                'error' => $e->getMessage(),
                'context' => $context->toArray()
            ]);
        }
    }

    public function searchAuditRecords(AuditQuery $query): AuditCollection
    {
        // Verify search authorization
        $this->verifySearchAuthorization($query);
        
        // Execute search with monitoring
        return $this->security->executeSecureOperation(
            fn() => $this->database->searchAuditRecords($query)
        );
    }

    public function getAuditReport(AuditReportConfig $config): AuditReport
    {
        // Verify report authorization
        $this->verifyReportAuthorization($config);
        
        // Generate report with monitoring
        return $this->security->executeSecureOperation(
            fn() => $this->generateAuditReport($config)
        );
    }

    private function verifySearchAuthorization(AuditQuery $query): void
    {
        if (!$this->security->canSearchAudit($query)) {
            throw new UnauthorizedAuditAccessException('Unauthorized audit search');
        }
    }

    private function verifyReportAuthorization(AuditReportConfig $config): void
    {
        if (!$this->security->canGenerateAuditReport($config)) {
            throw new UnauthorizedAuditAccessException('Unauthorized audit report generation');
        }
    }

    private function generateAuditReport(AuditReportConfig $config): AuditReport
    {
        $records = $this->database->getAuditRecords($config);
        return new AuditReport($records, $config);
    }
}
