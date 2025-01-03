<?php

namespace App\Http\Controllers;

use App\Core\Security\{AccessControlService, AuditService};
use App\Core\Services\{ValidationService, CacheService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

abstract class BaseController extends Controller
{
    protected AccessControlService $accessControl;
    protected ValidationService $validator;
    protected AuditService $auditService;
    protected CacheService $cache;

    public function __construct(
        AccessControlService $accessControl,
        ValidationService $validator,
        AuditService $auditService,
        CacheService $cache
    ) {
        $this->accessControl = $accessControl;
        $this->validator = $validator;
        $this->auditService = $auditService;
        $this->cache = $cache;
    }

    protected function executeAction(callable $action, string $operation): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $result = $action();
            
            DB::commit();
            
            $this->auditAction($operation, $result, true);
            
            return $this->successResponse($result);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->auditAction($operation, null, false, $e);
            
            throw $e;
        }
    }

    protected function validateRequest(Request $request, array $rules): array
    {
        return $this->validator->validate($request->all(), $rules);
    }

    protected function successResponse($data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data
        ], $status);
    }

    protected function errorResponse(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message
        ], $status);
    }

    protected function auditAction(
        string $operation,
        $result = null,
        bool $success = true,
        \Exception $error = null
    ): void {
        $this->auditService->logSecurityEvent('controller_action', [
            'operation' => $operation,
            'success' => $success,
            'user_id' => auth()->id(),
            'result' => $result,
            'error' => $error ? $error->getMessage() : null
        ]);
    }

    protected function cacheResponse(string $key, callable $callback, int $ttl = null): mixed
    {
        return $this->cache->remember($key, $callback, $ttl);
    }

    protected function clearResponseCache(array|string $keys): void
    {
        if (is_array($keys)) {
            foreach ($keys as $key) {
                $this->cache->forget($key);
            }
        } else {
            $this->cache->forget($keys);
        }
    }

    protected function generateCacheKey(string $prefix, array $params = []): string
    {
        $key = $prefix;
        
        if (!empty($params)) {
            $key .= ':' . md5(serialize($params));
        }
        
        if (auth()->check()) {
            $key .= ':user_' . auth()->id();
        }
        
        return $key;
    }
}
