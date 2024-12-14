<?php

namespace App\Core\Audit\Repositories;

class AnalysisRepository
{
    private EntityManagerInterface $em;
    private CacheInterface $cache;
    private EventDispatcherInterface $dispatcher;

    public function __construct(
        EntityManagerInterface $em,
        CacheInterface $cache,
        EventDispatcherInterface $dispatcher
    ) {
        $this->em = $em;
        $this->cache = $cache;
        $this->dispatcher = $dispatcher;
    }

    public function save(Analysis $analysis): void
    {
        $this->em->beginTransaction();
        
        try {
            $this->em->persist($analysis);
            $this->em->flush();
            
            $this->cache->delete($this->getCacheKey($analysis->getId()));
            
            $this->dispatcher->dispatch(
                new AnalysisSavedEvent($analysis)
            );
            
            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    public function find(string $id): ?Analysis
    {
        $cacheKey = $this->getCacheKey($id);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        $analysis = $this->em->find(Analysis::class, $id);
        
        if ($analysis) {
            $this->cache->set($cacheKey, $analysis);
        }
        
        return $analysis;
    }

    public function findByRequest(AnalysisRequest $request): array
    {
        $qb = $this->em->createQueryBuilder();
        
        return $qb->select('a')
            ->from(Analysis::class, 'a')
            ->where('a.requestHash = :hash')
            ->setParameter('hash', $this->hashRequest($request))
            ->getQuery()
            ->getResult();
    }

    public function findByDateRange(\DateTime $start, \DateTime $end): array
    {
        $qb = $this->em->createQueryBuilder();
        
        return $qb->select('a')
            ->from(Analysis::class, 'a')
            ->where('a.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }

    private function getCacheKey(string $id): string
    {
        return sprintf('analysis.%s', $id);
    }

    private function hashRequest(AnalysisRequest $request): string
    {
        return hash('sha256', serialize($request->toArray()));
    }
}

class ResultRepository
{
    private EntityManagerInterface $em;
    private EventDispatcherInterface $dispatcher;

    public function __construct(
        EntityManagerInterface $em,
        EventDispatcherInterface $dispatcher
    ) {
        $this->em = $em;
        $this->dispatcher = $dispatcher;
    }

    public function save(AnalysisResult $result): void
    {
        $this->em->beginTransaction();
        
        try {
            $this->em->persist($result);
            $this->saveFindings($result->getFindings());
            $this->em->flush();
            
            $this->dispatcher->dispatch(
                new ResultSavedEvent($result)
            );
            
            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    public function findByAnalysis(Analysis $analysis): array
    {
        $qb = $this->em->createQueryBuilder();
        
        return $qb->select('r')
            ->from(AnalysisResult::class, 'r')
            ->where('r.analysis = :analysis')
            ->setParameter('analysis', $analysis)
            ->getQuery()
            ->getResult();
    }

    private function saveFindings(array $findings): void
    {
        foreach ($findings as $finding) {
            $this->em->persist($finding);
        }
    }
}

class AnomalyRepository
{
    private EntityManagerInterface $em;
    private NotificationService $notifications;

    public function __construct(
        EntityManagerInterface $em,
        NotificationService $notifications
    ) {
        $this->em = $em;
        $this->notifications = $notifications;
    }

    public function save(Anomaly $anomaly): void
    {
        $this->em->beginTransaction();
        
        try {
            $this->em->persist($anomaly);
            $this->em->flush();
            
            if ($this->shouldNotify($anomaly)) {
                $this->notifications->sendAnomalyAlert($anomaly);
            }
            
            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    public function findBySeverity(string $severity): array
    {
        $qb = $this->em->createQueryBuilder();
        
        return $qb->select('a')
            ->from(Anomaly::class, 'a')
            ->where('a.severity = :severity')
            ->setParameter('severity', $severity)
            ->getQuery()
            ->getResult();
    }

    public function findByDateRange(\DateTime $start, \DateTime $end): array
    {
        $qb = $this->em->createQueryBuilder();
        
        return $qb->select('a')
            ->from(Anomaly::class, 'a')
            ->where('a.detectedAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }

    private function shouldNotify(Anomaly $anomaly): bool
    {
        return $anomaly->getSeverity() === 'critical' ||
               $anomaly->getConfidence() > 0.9;
    }
}

class PatternRepository
{
    private EntityManagerInterface $em;
    private CacheInterface $cache;

    public function __construct(
        EntityManagerInterface $em,
        CacheInterface $cache
    ) {
        $this->em = $em;
        $this->cache = $cache;
    }

    public function save(Pattern $pattern): void
    {
        $this->em->beginTransaction();
        
        try {
            $this->em->persist($pattern);
            $this->em->flush();
            
            $this->cache->delete($this->getCacheKey($pattern->getId()));
            
            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    public function findByType(string $type): array
    {
        $qb = $this->em->createQueryBuilder();
        
        return $qb->select('p')
            ->from(Pattern::class, 'p')
            ->where('p.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getResult();
    }

    public function findSimilar(Pattern $pattern, float $threshold = 0.8): array
    {
        $qb = $this->em->createQueryBuilder();
        
        return $qb->select('p')
            ->from(Pattern::class, 'p')
            ->where('p.type = :type')
            ->andWhere('p.similarity > :threshold')
            ->setParameter('type', $pattern->getType())
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    private function getCacheKey(string $id): string
    {
        return sprintf('pattern.%s', $id);
    }
}
