<?php

namespace App\Core\Audit;

class AuditRepository 
{
    private DatabaseConnection $db;
    private QueryBuilder $queryBuilder;
    private CacheManager $cache;
    private EventDispatcher $dispatcher;

    public function __construct(
        DatabaseConnection $db,
        QueryBuilder $queryBuilder,
        CacheManager $cache,
        EventDispatcher $dispatcher
    ) {
        $this->db = $db;
        $this->queryBuilder = $queryBuilder;
        $this->cache = $cache;
        $this->dispatcher = $dispatcher;
    }

    public function store(array $event): void 
    {
        DB::beginTransaction();
        
        try {
            $id = $this->db->insert('audit_events', [
                'type' => $event['type'],
                'action' => $event['action'],
                'data' => json_encode($event['data']),
                'user_id' => $event['user_id'],
                'ip_address' => $event['ip_address'],
                'metadata' => json_encode($event['metadata']),
                'timestamp' => $event['timestamp'],
                'trace_id' => $event['trace_id'],
                'severity' => $event['severity'],
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $this->storeAdditionalData($id, $event);
            $this->invalidateCache($event);
            
            $this->dispatcher->dispatch(new AuditEventStored($event));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuditStoreException("Failed to store audit event", 0, $e);
        }
    }

    public function getEvents(array $filters = []): array 
    {
        $cacheKey = $this->generateCacheKey($filters);
        
        return $this->cache->remember($cacheKey, 3600, function() use ($filters) {
            $query = $this->buildQuery($filters);
            
            $results = $query->get();
            
            return array_map(function ($row) {
                $row['data'] = json_decode($row['data'], true);
                $row['metadata'] = json_decode($row['metadata'], true);
                return $row;
            }, $results);
        });
    }

    public function purge(array $criteria): void 
    {
        DB::beginTransaction();
        
        try {
            $query = $this->queryBuilder
                ->delete()
                ->from('audit_events');

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            $affectedRows = $query->execute();
            
            $this->purgeAdditionalData($criteria);
            $this->invalidateAllCache();
            
            $this->dispatcher->dispatch(new AuditEventsPurged($criteria, $affectedRows));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuditPurgeException("Failed to purge audit events", 0, $e);
        }
    }

    protected function buildQuery(array $filters): QueryBuilder
    {
        $query = $this->queryBuilder
            ->select('*')
            ->from('audit_events');

        foreach ($filters as $field => $value) {
            if ($this->isValidField($field)) {
                $query->where($field, $value);
            }
        }

        if (isset($filters['from'])) {
            $query->where('timestamp', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('timestamp', '<=', $filters['to']);
        }

        if (isset($filters['severity'])) {
            $query->whereIn('severity', (array)$filters['severity']);
        }

        if (isset($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('data', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('metadata', 'LIKE', "%{$filters['search']}%");
            });
        }

        $query->orderBy('timestamp', 'DESC');

        if (isset($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        return $query;
    }

    protected function generateCacheKey(array $filters): string 
    {
        return 'audit_events:' . md5(serialize($filters));
    }

    protected function invalidateCache(array $event): void 
    {
        $this->cache->tags(['audit_events'])->flush();
    }

    protected function storeAdditionalData(int $eventId, array $event): void 
    {
        if (!empty($event['related_entities'])) {
            foreach ($event['related_entities'] as $entity) {
                $this->db->insert('audit_event_relations', [
                    'audit_event_id' => $eventId,
                    'entity_type' => $entity['type'],
                    'entity_id' => $entity['id']
                ]);
            }
        }
    }

    protected function isValidField(string $field): bool 
    {
        return in_array($field, [
            'id',
            'type',
            'action',
            'user_id',
            'ip_address',
            'severity',
            'trace_id'
        ]);
    }
}
