<?php

namespace App\Core\Analytics\Repository;

use App\Core\Analytics\Models\AnalyticsData;
use App\Core\Analytics\DTO\AnalyticsDTO;
use App\Core\Analytics\Events\AnalyticsRecorded;
use App\Core\Analytics\Services\AnalyticsProcessor;
use App\Core\Analytics\Services\MetricsCalculator;
use App\Core\Analytics\Services\ReportGenerator;
use App\Core\Analytics\Exceptions\AnalyticsException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class AnalyticsRepository extends BaseRepository implements AnalyticsRepositoryInterface
{
    protected const CACHE_KEY = 'analytics';
    protected const CACHE_TTL = 300; // 5 minutes

    protected AnalyticsProcessor $processor;
    protected MetricsCalculator $calculator;
    protected ReportGenerator $reportGenerator;

    public function __construct(
        CacheManagerInterface $cache,
        AnalyticsProcessor $processor,
        MetricsCalculator $calculator,
        ReportGenerator $reportGenerator
    ) {
        parent::__construct($cache);
        $this->processor = $processor;
        $this->calculator = $calculator;
        $this->reportGenerator = $reportGenerator;
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return AnalyticsData::class;
    }

    public function record(AnalyticsDTO $data): AnalyticsData
    {
        DB::beginTransaction();
        try {
            // Process analytics data
            $processedData = $this->processor->process($data);

            // Create analytics record
            $analytics = $this->model->create([
                'type' => $data->type,
                'user_id' => $data->userId,
                'page_url' => $data->pageUrl,
                'metrics' => $processedData['metrics'],
                'dimensions' => $processedData['dimensions'],
                'metadata' => $data->metadata,
                'session_id' => $data->sessionId,
                'timestamp' => now()
            ]);

            // Dispatch event
            Event::dispatch(new AnalyticsRecorded($analytics));

            // Clear relevant cache
            $this->clearCache();

            DB::commit();
            return $analytics->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new AnalyticsException("Failed to record analytics: {$e->getMessage()}", 0, $e);
        }
    }

    public function getPageViews(array $filters = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey('pageviews:' . md5(serialize($filters))),
            function() use ($filters) {
                $query = $this->model->where('type', 'pageview');

                if (isset($filters['from_date'])) {
                    $query->where('timestamp', '>=', $filters['from_date']);
                }

                if (isset($filters['to_date'])) {
                    $query->where('timestamp', '<=', $filters['to_date']);
                }

                return [
                    'total_views' => $query->count(),
                    'unique_views' => $query->distinct('session_id')->count(),
                    'by_page' => $query->select('page_url', DB::raw('count(*) as views'))
                        ->groupBy('page_url')
                        ->orderByDesc('views')
                        ->get()
                        ->pluck('views', 'page_url')
                        ->toArray(),
                    'by_hour' => $query->select(DB::raw('HOUR(timestamp) as hour'), DB::raw('count(*) as views'))
                        ->groupBy('hour')
                        ->pluck('views', 'hour')
                        ->toArray()
                ];
            }
        );
    }

    public function getUserEngagement(array $filters = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey('engagement:' . md5(serialize($filters))),
            function() use ($filters) {
                return $this->calculator->calculateEngagementMetrics($filters);
            }
        );
    }

    public function getContentPerformance(array $filters = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey('content:' . md5(serialize($filters))),
            function() use ($filters) {
                return $this->calculator->calculateContentMetrics($filters);
            }
        );
    }

    public function getSystemPerformance(array $filters = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey('system:' . md5(serialize($filters))),
            function() use ($filters) {
                return $this->calculator->calculateSystemMetrics($filters);
            }
        );
    }

    public function getUserFlow(array $filters = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey('flow:' . md5(serialize($filters))),
            function() use ($filters) {
                $query = $this->model->where('type', 'pageview');

                if (isset($filters['from_date'])) {
                    $query->where('timestamp', '>=', $filters['from_date']);
                }

                $sessions = $query->get()->groupBy('session_id');

                return [
                    'entry_pages' => $this->calculateEntryPages($sessions),
                    'exit_pages' => $this->calculateExitPages($sessions),
                    'flow_paths' => $this->calculateFlowPaths($sessions)
                ];
            }
        );
    }

    public function getConversionMetrics(array $filters = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey('conversions:' . md5(serialize($filters))),
            function() use ($filters) {
                return $this->calculator->calculateConversionMetrics($filters);
            }
        );
    }

    public function getRealTimeStats(): array
    {
        // Don't cache real-time stats
        $lastFiveMinutes = now()->subMinutes(5);

        return [
            'active_users' => $this->model->where('timestamp', '>=', $lastFiveMinutes)
                                        ->distinct('session_id')
                                        ->count(),
            'pageviews' => $this->model->where('timestamp', '>=', $lastFiveMinutes)
                                     ->where('type', 'pageview')
                                     ->count(),
            'top_pages' => $this->model->where('timestamp', '>=', $lastFiveMinutes)
                                     ->where('type', 'pageview')
                                     ->select('page_url', DB::raw('count(*) as views'))
                                     ->groupBy('page_url')
                                     ->orderByDesc('views')
                                     ->limit(10)
                                     ->get()
                                     ->pluck('views', 'page_url')
                                     ->toArray(),
            'user_locations' => $this->model->where('timestamp', '>=', $lastFiveMinutes)
                                         ->whereNotNull('metadata->location')
                                         ->select('metadata->location', DB::raw('count(*) as count'))
                                         ->groupBy('metadata->location')
                                         ->get()
                                         ->pluck('count', 'metadata->location')
                                         ->toArray()
        ];
    }

    public function getCustomReport(array $metrics, array $dimensions, array $filters = []): array
    {
        return $this->reportGenerator->generateReport($metrics, $dimensions, $filters);
    }

    public function exportData(array $filters, string $format = 'csv'): string
    {
        return $this->reportGenerator->exportData($filters, $format);
    }

    public function getTrendingContent(int $limit = 10, array $filters = []): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("trending:{$limit}:" . md5(serialize($filters))),
            function() use ($limit, $filters) {
                $query = $this->model->where('type', 'pageview');

                if (isset($filters['from_date'])) {
                    $query->where('timestamp', '>=', $filters['from_date']);
                }

                return $query->select('page_url', DB::raw('count(*) as views'))
                            ->groupBy('page_url')
                            ->orderByDesc('views')
                            ->limit($limit)
                            ->get();
            }
        );
    }

    public function getAlerts(): array
    {
        return $this->calculator->calculateAlerts();
    }

    protected function calculateEntryPages($sessions): array
    {
        $entryPages = [];
        foreach ($sessions as $sessionData) {
            $firstPage = $sessionData->sortBy('timestamp')->first();
            $page = $firstPage->page_url;
            $entryPages[$page] = ($entryPages[$page] ?? 0) + 1;
        }
        return $entryPages;
    }

    protected function calculateExitPages($sessions): array
    {
        $exitPages = [];
        foreach ($sessions as $sessionData) {
            $lastPage = $sessionData->sortByDesc('timestamp')->first();
            $page = $lastPage->page_url;
            $exitPages[$page] = ($exitPages[$page] ?? 0) + 1;
        }
        return $exitPages;
    }

    protected function calculateFlowPaths($sessions): array
    {
        $paths = [];
        foreach ($sessions as $sessionData) {
            $sessionPath = $sessionData->sortBy('timestamp')
                                     ->pluck('page_url')
                                     ->toArray();
            $pathKey = implode(' > ', $sessionPath);
            $paths[$pathKey] = ($paths[$pathKey] ?? 0) + 1;
        }
        arsort($paths);
        return array_slice($paths, 0, 10);
    }
}
