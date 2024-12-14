<?php

namespace App\Core\Security;

class DatabaseProtectionService implements DatabaseSecurityInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;
    private Cache $cache;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        AuditLogger $auditLogger,
        MetricsCollector $metrics,
        Cache $cache
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
        $this->cache = $cache;
    }

    public function secureInsert(string $table, array $data): bool
    {
        $operationId = $this->metrics->startOperation('secure_insert');

        DB::beginTransaction();

        try {
            // Validate data
            $validatedData = $this->validateData($table, $data);

            // Encrypt sensitive fields
            $securedData = $this->encryptSensitiveFields($table, $validatedData);

            // Generate integrity hash
            $securedData['_integrity_hash'] = $this->generateIntegrityHash($securedData);

            // Insert with audit
            $result = DB::table($table)->insert($securedData);

            // Log operation
            $this->auditLogger->logDataAccess(
                'insert',
                $table,
                $securedData['id'] ?? null,
                auth()->user()
            );

            DB::commit();
            $this->metrics->recordSuccess($operationId);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->metrics->recordFailure($operationId, $e);
            throw new DatabaseSecurityException('Secure insert failed', 0, $e);
        }
    }

    public function secureUpdate(string $table, array $criteria, array $data): bool
    {
        $operationId = $this->metrics->startOperation('secure_update');

        DB::beginTransaction();

        try {
            // Validate update criteria
            $this->validateCriteria($criteria);

            // Validate update data
            $validatedData = $this->validateData($table, $data);

            // Encrypt sensitive fields
            $securedData = $this->encryptSensitiveFields($table, $validatedData);

            // Generate new integrity hash
            $securedData['_integrity_hash'] = $this->generateIntegrityHash($securedData);

            // Verify existing records
            $this->verifyExistingRecords($table, $criteria);

            // Update with audit
            $result = DB::table($table)
                ->where($criteria)
                ->update($securedData);

            // Log operation
            $this->auditLogger->logDataAccess(
                'update',
                $table,
                json_encode($criteria),
                auth()->user()
            );

            DB::commit();
            $this->metrics->recordSuccess($operationId);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->metrics->recordFailure($operationId, $e);
            throw new DatabaseSecurityException('Secure update failed', 0, $e);
        }
    }

    public function secureDelete(string $table, array $criteria): bool
    {
        $operationId = $this->metrics->startOperation('secure_delete');

        DB::beginTransaction();

        try {
            // Validate delete criteria
            $this->validateCriteria($criteria);

            // Verify existing records
            $this->verifyExistingRecords($table, $criteria);

            // Perform soft delete if supported
            if ($this->supportsSoftDelete($table)) {
                $result = $this->performSoftDelete($table, $criteria);
            } else {
                $result = DB::table($table)->where($criteria)->delete();
            }

            // Log operation
            $this->auditLogger->logDataAccess(
                'delete',
                $table,
                json_encode($criteria),
                auth()->user()
            );

            DB::commit();
            $this->metrics->recordSuccess($operationId);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->metrics->recordFailure($operationId, $e);
            throw new DatabaseSecurityException('Secure delete failed', 0, $e);
        }
    }

    public function secureSelect(string $table, array $criteria, array $columns = ['*']): Collection
    {
        $operationId = $this->metrics->startOperation('secure_select');

        try {
            // Validate select criteria
            $this->validateCriteria($criteria);

            // Check column permissions
            $this->validateColumnAccess($table, $columns);

            // Perform select
            $data = DB::table($table)
                ->where($criteria)
                ->select($columns)
                ->get();

            // Verify integrity of results
            $this->verifyResultIntegrity($data);

            // Decrypt sensitive fields
            $decryptedData = $this->decryptSensitiveFields($table, $data);

            // Log access
            $this->auditLogger->logDataAccess(
                'select',
                $table,
                json_encode($criteria),
                auth()->user()
            );

            $this->metrics->recordSuccess($operationId);

            return $decryptedData;

        } catch (\Exception $e) {
            $this->metrics->recordFailure($operationId, $e);
            throw new DatabaseSecurityException('Secure select failed', 0, $e);
        }
    }

    private function validateData(string $table, array $data): array
    {
        $rules = $this->getValidationRules($table);
        return $this->validator->validate($data, $rules);
    }

    private function validateCriteria(array $criteria): void
    {
        if (empty($criteria)) {
            throw new ValidationException('Empty criteria not allowed');
        }

        foreach ($criteria as $field => $value) {
            if ($this->isUnsafeValue($value)) {
                throw new ValidationException('Unsafe criteria value detected');
            }
        }
    }

    private function encryptSensitiveFields(string $table, array $data): array
    {
        $sensitiveFields = $this->getSensitiveFields($table);
        
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->encryption->encrypt($data[$field]);
            }
        }

        return $data;
    }

    private function decryptSensitiveFields(string $table, Collection $data): Collection
    {
        $sensitiveFields = $this->getSensitiveFields($table);
        
        return $data->map(function ($row) use ($sensitiveFields) {
            foreach ($sensitiveFields as $field) {
                if (isset($row->$field)) {
                    $row->$field = $this->encryption->decrypt($row->$field);
                }
            }
            return $row;
        });
    }

    private function generateIntegrityHash(array $data): string
    {
        unset($data['_integrity_hash']);
        return $this->encryption->hash(json_encode($data));
    }

    private function verifyExistingRecords(string $table, array $criteria): void
    {
        $records = DB::table($table)->where($criteria)->get();
        
        foreach ($records as $record) {
            if (!$this->verifyRecordIntegrity($record)) {
                throw new IntegrityException('Data integrity violation detected');
            }
        }
    }

    private function verifyRecordIntegrity($record): bool
    {
        $data = (array) $record;
        $storedHash = $data['_integrity_hash'] ?? null;
        
        if (!$storedHash) {
            return false;
        }

        return $this->encryption->verifyHash(
            json_encode($this->prepareForHashing($data)),
            $storedHash
        );
    }

    private function prepareForHashing(array $data): array
    {
        unset($data['_integrity_hash']);
        ksort($data);
        return $data;
    }

    private function validateColumnAccess(string $table, array $columns): void
    {
        $allowedColumns = $this->getAllowedColumns($table);
        
        foreach ($columns as $column) {
            if ($column !== '*' && !in_array($column, $allowedColumns)) {
                throw new UnauthorizedException("Access to column {$column} not allowed");
            }
        }
    }

    private function supportsSoftDelete(string $table): bool
    {
        return in_array($table, config('database.soft_delete_tables', []));
    }

    private function performSoftDelete(string $table, array $criteria): bool
    {
        return DB::table($table)
            ->where($criteria)
            ->update([
                'deleted_at' => now(),
                'deleted_by' => auth()->id()
            ]);
    }
}

interface DatabaseSecurityInterface
{
    public function secureInsert(string $table, array $data): bool;
    public function secureUpdate(string $table, array $criteria, array $data): bool;
    public function secureDelete(string $table, array $criteria): bool;
    public function secureSelect(string $table, array $criteria, array $columns = ['*']): Collection;
}
