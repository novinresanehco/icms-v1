<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Exceptions\{ValidationException, AuthorizationException};

class ValidationService implements ValidationInterface 
{
    private AuthorizationManager $auth;
    private array $rules;
    private MetricsCollector $metrics;

    public function validateContent(array $data): array 
    {
        return $this->executeValidation('content', $data, [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'author_id' => ['required', 'exists:users,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'meta' => ['array'],
            'published_at' => ['date_format:Y-m-d H:i:s'],
            'media' => ['array'],
            'media.*' => ['array', 'required_array_keys:type,url,meta']
        ]);
    }

    public function validateUser(array $data): array 
    {
        return $this->executeValidation('user', $data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:12', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'],
            'role_id' => ['required', 'exists:roles,id']
        ]);
    }

    public function validateMedia(array $data): array 
    {
        return $this->executeValidation('media', $data, [
            'type' => ['required', 'string', 'in:image,video,document'],
            'url' => ['required', 'url'],
            'mime_type' => ['required', 'string'],
            'size' => ['required', 'integer', 'max:' . config('cms.media.max_size')],
            'meta' => ['array']
        ]);
    }

    private function executeValidation(string $type, array $data, array $rules): array 
    {
        $startTime = microtime(true);
        
        try {
            if (!$this->auth->canValidate($type)) {
                throw new AuthorizationException("Unauthorized validation attempt for: $type");
            }

            $validator = validator($data, $rules);
            
            if ($validator->fails()) {
                throw new ValidationException(
                    "Validation failed for $type: " . json_encode($validator->errors()->all())
                );
            }

            $validated = $validator->validated();
            
            $this->validateBusinessRules($type, $validated);
            $this->validateSecurityConstraints($type, $validated);
            
            $this->metrics->recordValidation([
                'type' => $type,
                'duration' => microtime(true) - $startTime,
                'success' => true
            ]);

            return $validated;

        } catch (\Throwable $e) {
            $this->metrics->recordValidation([
                'type' => $type,
                'duration' => microtime(true) - $startTime,
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    private function validateBusinessRules(string $type, array $data): void 
    {
        $rules = $this->rules[$type] ?? [];
        
        foreach ($rules as $rule) {
            if (!$rule->validate($data)) {
                throw new ValidationException("Business rule validation failed: {$rule->getMessage()}");
            }
        }
    }

    private function validateSecurityConstraints(string $type, array $data): void 
    {
        if (isset($data['content'])) {
            $this->validateXSS($data['content']);
            $this->validateSQLInjection($data['content']);
        }

        if (isset($data['meta'])) {
            $this->validateMetadata($data['meta']);
        }

        if (isset($data['url'])) {
            $this->validateURL($data['url']);
        }
    }

    private function validateXSS(string $content): void 
    {
        $filtered = strip_tags($content, config('cms.security.allowed_tags'));
        if ($filtered !== $content) {
            throw new ValidationException('Potential XSS detected in content');
        }
    }

    private function validateSQLInjection(string $content): void 
    {
        $patterns = [
            '/\bSELECT\b/i',
            '/\bUNION\b/i',
            '/\bINSERT\b/i',
            '/\bUPDATE\b/i',
            '/\bDELETE\b/i',
            '/\bDROP\b/i',
            '/\bEXEC\b/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new ValidationException('Potential SQL injection detected');
            }
        }
    }

    private function validateMetadata(array $meta): void 
    {
        array_walk_recursive($meta, function($value) {
            if (is_string($value)) {
                $this->validateXSS($value);
                $this->validateSQLInjection($value);
            }
        });
    }

    private function validateURL(string $url): void 
    {
        $allowedDomains = config('cms.security.allowed_domains');
        $domain = parse_url($url, PHP_URL_HOST);
        
        if (!in_array($domain, $allowedDomains)) {
            throw new ValidationException('Invalid domain in URL');
        }
    }
}
