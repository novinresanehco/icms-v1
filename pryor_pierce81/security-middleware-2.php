<?php

namespace App\Core\Middleware;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\SecurityException;
use Psr\Log\LoggerInterface;

class SecurityMiddleware implements MiddlewareInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $requestId = $this->generateRequestId();

        try {
            $this->validateRequest($request);
            $this->enforceSecurityPolicy($request);
            
            $response = $handler->handle($request);
            
            $this->validateResponse($response);
            $this->logSecureRequest($requestId, $request, $response);
            
            return $response;

        } catch (\Exception $e) {
            $this->handleSecurityFailure($requestId, $request, $e);
            throw new SecurityException('Security check failed', 0, $e);
        }
    }

    private function validateRequest(Request $request): void
    {
        if (!$this->validateHeaders($request)) {
            throw new SecurityException('Invalid security headers');
        }

        if (!$this->validateOrigin($request)) {
            throw new SecurityException('Invalid request origin');
        }

        if (!$this->validateToken($request)) {
            throw new SecurityException('Invalid security token');
        }

        foreach ($this->config['request_validators'] as $validator) {
            if (!$validator->validate($request)) {
                throw new SecurityException("Validation failed: {$validator->getName()}");
            }
        }
    }

    private function enforceSecurityPolicy(Request $request): void
    {
        foreach ($this->config['security_policies'] as $policy) {
            $this->security->enforcePolicy($policy, [
                'request' => $request,
                'timestamp' => time()
            ]);
        }
    }

    private function validateResponse(Response $response): void
    {
        if (!$this->validateResponseHeaders($response)) {
            throw new SecurityException('Invalid response headers');
        }

        if (!$this->validateResponseContent($response)) {
            throw new SecurityException('Invalid response content');
        }

        foreach ($this->config['response_validators'] as $validator) {
            if (!$validator->validate($response)) {
                throw new SecurityException("Response validation failed: {$validator->getName()}");
            }
        }
    }

    private function handleSecurityFailure(string $id, Request $request, \Exception $e): void
    {
        $this->logger->critical('Security middleware failure', [
            'request_id' => $id,
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'error' => $e->getMessage()
        ]);

        $this->security->handleSecurityBreach([
            'request_id' => $id,
            'request' => $request,
            'error' => $e
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'security_policies' => [
                'csrf_protection',
                'xss_prevention',
                'sql_injection_prevention',
                'rate_limiting'
            ],
            'request_validators' => [
                new HeaderValidator(),
                new OriginValidator(),
                new TokenValidator()
            ],
            'response_validators' => [
                new HeaderSecurityValidator(),
                new ContentSecurityValidator(),
                new XssValidator()
            ],
            'log_requests' => true,
            'enforce_ssl' => true
        ];
    }
}
