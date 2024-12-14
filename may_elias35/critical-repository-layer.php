<?php

namespace App\Repositories;

class CriticalContentRepository implements ContentRepositoryInterface
{
    private DB $database;
    private SecurityService $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private MonitoringService $monitor;

    public function findById(int $id, SecurityContext $context): ContentEntity
    {
        $this->monitor->startOperation('repository_find');

        try {
            // Security verification
            $this->security->validateAccess($context, 'read', $id);

            // Cache check with security
            return $this->cache->remember(['content', $id], function() use ($id, $context) {
                // Protected query execution
                $data = $this->executeSecureQuery(function() use ($id) {
                    return $this->database
                        ->table('contents')
                        ->where('id', $id)
                        ->where('active', true)
                        ->first();
                });

                if (!$data) {
                    throw new NotFoundException('Content not found');
                }

                // Data validation and transformation
                $validatedData = $this->validator->validateRetrievedData($data);
                return new ContentEntity($validatedData);
            });

        } catch (\Exception $e) {
            $this->handleRepositoryFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation();
        }
    }

    public function create(array $data, SecurityContext $context): ContentEntity
    {
        $this->monitor->startOperation('repository_create');
        DB::beginTransaction();

        try {
            // Security checks
            $this->security->validateAccess($context, 'create');
            $validatedData = $this->validator->validateCreationData($data);

            // Protected creation
            $content = $this->executeSecureQuery(function() use ($validatedData, $context) {
                $id = $this->database->table('contents')->insertGetId([
                    'data' => $this->security->encryptData($validatedData),
                    'created_by' => $context->getUserId(),
                    'created_at' => now(),
                    'active' => true
                ]);

                return $this->findById($id, $context);
            });

            // Post-creation verification
            $this->verifyCreation($content);

            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRepositoryFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation();
        }
    }

    public function update(int $id, array $data, SecurityContext $context): ContentEntity
    {
        $this->monitor->startOperation('repository_update');
        DB::beginTransaction();

        try {
            // Security verification
            $this->security->validateAccess($context, 'update', $id);
            $validatedData = $this->validator->validateUpdateData($data);

            // Protected update
            $content = $this->executeSecureQuery(function() use ($id, $validatedData, $context) {
                $updated = $this->database->table('contents')
                    ->where('id', $id)
                    ->where('active', true)
                    ->update([
                        'data' => $this->security->encryptData($validatedData),
                        'updated_by' => $context->getUserId(),
                        'updated_at' => now()
                    ]);

                if (!$updated) {
                    throw new NotFoundException('Content not found');
                }

                return $this->findById($id, $context);
            });

            // Post-update verification
            $this->verifyUpdate($content);

            DB::commit();
            $this->cache->invalidate(['content', $id]);
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRepositoryFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation();
        }
    }

    private function executeSecureQuery(callable $query)
    {
        return $this->monitor->track(function() use ($query) {
            return $query();
        });
    }

    private function verifyCreation(ContentEntity $content): void
    {
        if (!$this->validator->verifyContentIntegrity($content)) {
            throw new IntegrityException('Content integrity verification failed');
        }

        if (!$this->security->verifyContentSecurity($content)) {
            throw new SecurityException('Content security verification failed');
        }
    }

    private function verifyUpdate(ContentEntity $content): void
    {
        if (!$this->validator->verifyContentIntegrity($content)) {
            throw new IntegrityException('Content integrity verification failed');
        }

        if (!$this->security->verifyContentSecurity($content)) {
            throw new SecurityException('Content security verification failed');
        }
    }

    private function handleRepositoryFailure(\Exception $e): void
    {
        $this->monitor->logRepositoryFailure($e);
        
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
        
        if ($e instanceof IntegrityException) {
            $this->validator->handleIntegrityFailure($e);
        }
    }
}
