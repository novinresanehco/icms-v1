<?php

namespace App\Core\CMS;

class CriticalCMSService
{
    private $auth;
    private $monitor;
    private $cache;
    private $storage;

    public function handle(Request $req): Response
    {
        $operation = $req->getOperation();
        $monitorId = $this->monitor->start($operation);

        try {
            // Security check first
            $this->auth->validateRequest($req);

            // Try cache for read operations
            if ($operation === 'read' && $cached = $this->cache->get($req->getId())) {
                return new Response($cached);
            }

            // Process operation
            $result = $this->processOperation($req);

            // Cache if needed
            if ($operation === 'read') {
                $this->cache->set($req->getId(), $result);
            }

            return new Response($result);

        } catch (\Exception $e) {
            $this->monitor->logFailure($monitorId, $e);
            throw $e;
        } finally {
            $this->monitor->end($monitorId);
        }
    }

    private function processOperation(Request $req): array
    {
        DB::beginTransaction();
        
        try {
            $result = match($req->getOperation()) {
                'create' => $this->storage->create($req->getData()),
                'read'   => $this->storage->find($req->getId()),
                'update' => $this->storage->update($req->getId(), $req->getData()),
                'delete' => $this->storage->delete($req->getId())
            };

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
