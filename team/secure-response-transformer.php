namespace App\Core\Http;

class SecureResponseTransformer
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function transform($data, SecurityContext $context): array
    {
        // Validate data before transformation
        $this->validator->validateResponseData($data);

        // Filter sensitive data
        $filtered = $this->security->filterSensitiveData($data);

        // Transform data structure
        $transformed = $this->transformData($filtered);

        // Add security metadata
        $secured = $this->addSecurityMetadata($transformed, $context);

        // Log response
        $this->audit->logApiResponse($context, $secured);

        return $secured;
    }

    private function transformData($data): array
    {
        return [
            'success' => true,
            'timestamp' => time(),
            'data' => $data,
            'meta' => [
                'version' => config('api.version'),
                'environment' => app()->environment()
            ]
        ];
    }

    private function addSecurityMetadata(array $data, SecurityContext $context): array
    {
        return array_merge($data, [
            'security' => [
                'requestId' => $context->getRequestId(),
                'signature' => $this->generateSignature($data),
                'permissions' => $context->getPermissions()
            ]
        ]);
    }

    private function generateSignature(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode($data),
            config('app.key')
        );
    }
}
