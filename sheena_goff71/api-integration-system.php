<?php

namespace App\Core\API;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\{SecurityContext, RateLimiter};
use App\Core\Services\{ValidationService, TransformerService, AuditService};
use App\Core\Exceptions\{APIException, SecurityException, ValidationException};

class APIManager implements APIManagerInterface
{
    private ValidationService $validator;
    private TransformerService $transformer;
    private RateLimiter $rateLimiter;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        TransformerService $transformer,
        RateLimiter $rateLimiter,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->transformer = $transformer;
        $this->rateLimiter = $rateLimiter;
        $this->audit = $audit;
        $this->config = config('api');
    }

    public function handleRequest(Request $request, SecurityContext $context): Response
    {
        try {
            // Validate request
            $this->validateRequest($request);

            // Check rate limits
            $this->checkRateLimits($request, $context);

            // Process request
            return DB::transaction(function() use ($request, $context) {
                // Transform request
                $transformed = $this->transformRequest($request);

                // Execute operation
                $result = $this->executeOperation($transformed, $context);

                // Transform response
                $response = $this->transformResponse($result);

                // Log successful operation
                $this->audit->logAPIOperation($request, $response, $context);

                return $response;
            });

        } catch (\Exception $e) {
            $this->handleRequestFailure($e, $request, $context);
            throw new APIException('API request failed: ' . $e->getMessage());
        }
    }

    public function integrateService(string $service, array $config, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($service, $config, $context) {
            try {
                // Validate service config
                $this->validateServiceConfig($config);

                // Check security requirements
                $this->verifySecurityRequirements($service, $config);

                // Register service
                $this->registerService($service, $config);

                // Configure integration
                $this->configureIntegration($service, $config);

                // Test connection
                $this->testServiceConnection($service);

                // Log integration
                $this->audit->logServiceIntegration($service, $context);

                return true;

            } catch (\Exception $e) {
                $this->handleIntegrationFailure($e, $service, $context);
                throw new APIException('Service integration failed: ' . $e->getMessage());
            }
        });
    }

    public function executeServiceCall(string $service, string $operation, array $data, SecurityContext $context): mixed
    {
        try {
            // Validate operation
            $this->validateServiceOperation($service, $operation);

            // Check service status
            $this->verifyServiceStatus($service);

            // Process call
            return DB::transaction(function() use ($service, $operation, $data, $context) {
                // Transform request data
                $transformed = $this->transformServiceData($data, $service);

                // Execute call
                $result = $this->executeServiceOperation($service, $operation, $transformed);

                // Validate response
                $this->validateServiceResponse($result, $service, $operation);

                // Log operation
                $this->audit->logServiceOperation($service, $operation, $context);

                return $result;
            });

        } catch (\Exception $e) {
            $this->handleServiceCallFailure($e, $service, $operation, $context);
            throw new APIException('Service call failed: ' . $e->getMessage());
        }
    }

    private function validateRequest(Request $request): void
    {
        if (!$this->validator->validateAPIRequest($request)) {
            throw new ValidationException('Invalid API request format');
        }
    }

    private function checkRateLimits(Request $request, SecurityContext $context): void
    {
        if (!$this->rateLimiter->checkLimit($request, $context)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function transformRequest(Request $request): Request
    {
        return $this->transformer->transformRequest(
            $request,
            $this->config['transformation_rules']
        );
    }

    private function executeOperation(Request $request, SecurityContext $context): mixed
    {
        $handler = $this->resolveOperationHandler($request->getOperation());
        return $handler->execute($request, $context);
    }

    private function transformResponse(mixed $result): Response
    {
        return $this->transformer->transformResponse(
            $result,
            $this->config['response_rules']
        );
    }

    private function validateServiceConfig(array $config): void
    {
        if (!$this->validator->validateServiceConfig($config)) {
            throw new ValidationException('Invalid service configuration');
        }
    }

    private function verifySecurityRequirements(string $service, array $config): void
    {
        if (!$this->meetsSecurityRequirements($service, $config)) {
            throw new SecurityException('Service does not meet security requirements');
        }
    }

    private function registerService(string $service, array $config): void
    {
        DB::table('integrated_services')->insert([
            'service' => $service,
            'config' => json_encode($config),
            'status' => 'active',
            'created_at' => now()
        ]);
    }

    private function configureIntegration(string $service, array $config): void
    {
        $integration = new ServiceIntegration($service, $config);
        $integration->configure();
    }

    private function testServiceConnection(string $service): void
    {
        $tester = new ServiceConnectionTester($service);
        if (!$tester->test()) {
            throw new APIException('Service connection test failed');
        }
    }

    private function validateServiceOperation(string $service, string $operation): void
    {
        if (!$this->isValidOperation($service, $operation)) {
            throw new ValidationException('Invalid service operation');
        }
    }

    private function verifyServiceStatus(string $service): void
    {
        if (!$this->isServiceOperational($service)) {
            throw new APIException('Service is not operational');
        }
    }

    private function handleRequestFailure(\Exception $e, Request $request, SecurityContext $context): void
    {
        $this->audit->logAPIFailure($request, $e, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleIntegrationFailure(\Exception $e, string $service, SecurityContext $context): void
    {
        $this->audit->logIntegrationFailure($service, $e, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleServiceCallFailure(\Exception $e, string $service, string $operation, SecurityContext $context): void
    {
        $this->audit->logServiceCallFailure($service, $operation, $e, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
