namespace App\Core\Data;

class DataManager implements DataManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private Repository $repository;
    private ValidationService $validator;
    private AuditLogger $logger;
    private EventDispatcher $events;

    public function store(string $key, array $data): DataResult
    {
        return $this->security->executeCriticalOperation(
            new StoreDataOperation($key, $data, function() use ($key, $data) {
                $validated = $this->validator->validate($data);

                DB::beginTransaction();
                try {
                    $result = $this->repository->store($key, $validated);
                    
                    $this->cache->tags(['data'])->forget($key);
                    $this->events->dispatch(new DataStored($key, $result));
                    
                    $this->logger->log('data.stored', [
                        'key' => $key,
                        'checksum' => hash('sha256', serialize($validated))
                    ]);

                    DB::commit();
                    return $result;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function retrieve(string $key): DataResult
    {
        return $this->cache->tags(['data'])->remember(
            $key,
            3600,
            function() use ($key) {
                $result = $this->repository->find($key);
                
                if (!$result) {
                    throw new DataNotFoundException();
                }

                $this->logger->log('data.retrieved', [
                    'key' => $key,
                    'timestamp' => now()
                ]);

                return $result;
            }
        );
    }

    public function update(string $key, array $data): DataResult
    {
        return $this->security->executeCriticalOperation(
            new UpdateDataOperation($key, $data, function() use ($key, $data) {
                $validated = $this->validator->validate($data);
                
                DB::beginTransaction();
                try {
                    $existing = $this->repository->find($key);
                    
                    if (!$existing) {
                        throw new DataNotFoundException();
                    }

                    $result = $this->repository->update($key, $validated);
                    
                    $this->cache->tags(['data'])->forget($key);
                    $this->events->dispatch(new DataUpdated($key, $result));
                    
                    $this->logger->log('data.updated', [
                        'key' => $key,
                        'changes' => array_keys($validated)
                    ]);

                    DB::commit();
                    return $result;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function delete(string $key): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteDataOperation($key, function() use ($key) {
                DB::beginTransaction();
                try {
                    $existing = $this->repository->find($key);
                    
                    if (!$existing) {
                        throw new DataNotFoundException();
                    }

                    $this->repository->delete($key);
                    
                    $this->cache->tags(['data'])->forget($key);
                    $this->events->dispatch(new DataDeleted($key));
                    
                    $this->logger->log('data.deleted', [
                        'key' => $key,
                        'timestamp' => now()
                    ]);

                    DB::commit();
                    return true;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function batchStore(array $items): BatchResult
    {
        return $this->security->executeCriticalOperation(
            new BatchStoreOperation($items, function() use ($items) {
                DB::beginTransaction();
                try {
                    $results = [];
                    $failed = [];

                    foreach ($items as $key => $data) {
                        try {
                            $validated = $this->validator->validate($data);
                            $results[$key] = $this->repository->store($key, $validated);
                            $this->cache->tags(['data'])->forget($key);
                        } catch (\Exception $e) {
                            $failed[$key] = $e->getMessage();
                        }
                    }

                    if (empty($results)) {
                        throw new BatchOperationFailedException();
                    }

                    $this->events->dispatch(new BatchDataStored($results));
                    
                    $this->logger->log('data.batch_stored', [
                        'successful' => count($results),
                        'failed' => count($failed)
                    ]);

                    DB::commit();
                    return new BatchResult($results, $failed);
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function search(array $criteria): Collection
    {
        $cacheKey = 'search.' . hash('sha256', serialize($criteria));
        
        return $this->cache->tags(['data', 'search'])->remember(
            $cacheKey,
            1800,
            function() use ($criteria) {
                $results = $this->repository->search($criteria);
                
                $this->logger->log('data.searched', [
                    'criteria' => $criteria,
                    'results' => $results->count()
                ]);

                return $results;
            }
        );
    }
}
