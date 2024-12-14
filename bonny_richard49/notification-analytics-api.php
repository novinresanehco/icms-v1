<?php

namespace App\Core\Notification\Analytics\Api;

use App\Core\Notification\Analytics\NotificationAnalytics;
use App\Core\Notification\Analytics\RealTime\RealTimeProcessor;
use App\Core\Notification\Analytics\Cache\AnalyticsCacheStrategy;
use App\Core\Notification\Analytics\Exceptions\AnalyticsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AnalyticsApiController extends Controller
{
    private NotificationAnalytics $analytics;
    private RealTimeProcessor $realTimeProcessor;
    private AnalyticsCacheStrategy $cacheStrategy;

    public function __construct(
        NotificationAnalytics $analytics,
        RealTimeProcessor $realTimeProcessor,
        AnalyticsCacheStrategy $cacheStrategy
    ) {
        $this->analytics = $analytics;
        $this->realTimeProcessor = $realTimeProcessor;
        $this->cacheStrategy = $cacheStrategy;
    }

    public function getPerformanceMetrics(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'start_date' => 'date|before:tomorrow',
                'end_date' => 'date|after:start_date',
                'channel' => 'string|nullable',
                'type' => 'string|nullable'
            ]);

            $data = $this->analytics->analyzePerformance($filters);
            return response()->json(['data' => $data]);
        } catch (AnalyticsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function getUserSegmentAnalysis(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'segment' => 'string|nullable',
                'period' => 'integer|min:1|max:90',
                'metrics' => 'array|nullable'
            ]);

            $data = $this->analytics->analyzeUserSegments($filters);
            return response()->json(['data' => $data]);
        } catch (AnalyticsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function getChannelEffectiveness(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'channel' => 'string|nullable',
                'period' => 'integer|min:1|max:90',
                'metrics' => 'array|nullable'
            ]);

            $data = $this->analytics->analyzeChannelEffectiveness($filters);
            return response()->json(['data' => $data]);
        } catch (AnalyticsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function getABTestResults(Request $request, string $testId): JsonResponse
    {
        try {
            $data = $this->analytics->analyzeABTests($testId);
            return response()->json(['data' => $data]);
        } catch (AnalyticsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function getRealTimeMetrics(Request $request): JsonResponse
    {
        try {
            $metric = $request->validate([
                'metric' => 'required|string',
                'duration' => 'integer|min:60|max:3600'
            ]);

            $data = $this->realTimeProcessor->getMetricTrend(
                $metric['metric'],
                $metric['duration'] ?? 300
            );

            return response()->json(['data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function clearAnalyticsCache(Request $request): JsonResponse
    {
        try {
            $type = $request->validate(['type' => 'required|string'])['type'];
            $this->cacheStrategy->invalidateReport($type);
            return response()->json(['message' => 'Cache cleared successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
