<?php

namespace App\Core\Services;

use App\Core\Interfaces\ValidationServiceInterface;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Validator;
use Psr\Log\LoggerInterface;

class ValidationService implements ValidationServiceInterface
{
    private LoggerInterface $logger;
    private array $config;

    private const PASSWORD_MIN_LENGTH = 12;
    private const PASSWORD_SCORE_WEIGHTS = [
        'length' => 0.3,
        'numbers' => 0.2,
        'special' => 0.2,
        'uppercase' => 0.15,
        'lowercase' => 0.15
    ];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = config('validation');
    }

    public function validateData(array $data, array $rules): array
    {
        try {
            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                throw new ValidationException(
                    'Data validation failed: ' . implode(', ', $validator->errors()->all())
                );
            }

            return $validator->validated();
        } catch (\Exception $e) {
            $this->handleError('Data validation error', $e, $data);
        }
    }

    public function validateContent(array $content): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:contents,slug',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'type' => 'required|string|max:50',
            'metadata' => 'array',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id',
            'media' => 'array',
            'media.*' => 'file|mimes:jpeg,png,pdf|max:10240'
        ];

        return $this->validateData($content, $rules);
    }

    public function validateUser(array $userData): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'string', function($attribute, $value, $fail) {
                if ($this->calculatePasswordStrength($value) < 4) {
                    $fail('The password is not strong enough.');
                }
            }],
            'role_id' => 'required|exists:roles,id'
        ];

        return $this->validateData($userData, $rules);
    }

    public function validatePermissions(array $permissions): array
    {
        $rules = [
            'role_id' => 'required|exists:roles,id',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id'
        ];

        return $this->validateData($permissions, $rules);
    }

    public function calculatePasswordStrength(string $password): float
    {
        $score = 0;

        // Length check
        $length = strlen($password);
        if ($length >= self::PASSWORD_MIN_LENGTH) {
            $score += (($length / 20) * self::PASSWORD_SCORE_WEIGHTS['length']);
        }

        // Numbers check
        if (preg_match('/\d/', $password)) {
            $score += self::PASSWORD_SCORE_WEIGHTS['numbers'];
        }

        // Special characters check
        if (preg_match('/[^A-Za-z0-9]/', $password)) {
            $score += self::PASSWORD_SCORE_WEIGHTS['special'];
        }

        // Uppercase check
        if (preg_match('/[A-Z]/', $password)) {
            $score += self::PASSWORD_SCORE_WEIGHTS['uppercase'];
        }

        // Lowercase check
        if (preg_match('/[a-z]/', $password)) {
            $score += self::PASSWORD_SCORE_WEIGHTS['lowercase'];
        }

        return min($score * 5, 5);
    }

    public function sanitizeInput(array $data, array $fields): array
    {
        $sanitized = [];

        foreach ($fields as $field => $type) {
            if (!isset($data[$field])) {
                continue;
            }

            $sanitized[$field] = match($type) {
                'string' => $this->sanitizeString($data[$field]),
                'html' => $this->sanitizeHtml($data[$field]),
                'email' => $this->sanitizeEmail($data[$field]),
                'url' => $this->sanitizeUrl($data[$field]),
                'integer' => $this->sanitizeInteger($data[$field]),
                'float' => $this->sanitizeFloat($data[$field]),
                'array' => $this->sanitizeArray($data[$field]),
                default => $data[$field]
            };
        }

        return $sanitized;
    }

    private function sanitizeString(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    private function sanitizeHtml(string $value): string
    {
        return strip_tags($value, $this->config['allowed_html_tags']);
    }

    private function sanitizeEmail(string $value): string
    {
        $email = filter_var(trim($value), FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function sanitizeUrl(string $value): string
    {
        $url = filter_var(trim($value), FILTER_SANITIZE_URL);
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private function sanitizeInteger($value): int
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    private function sanitizeFloat($value): float
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    private function sanitizeArray(array $value): array
    {
        return array_map(fn($item) => is_string($item) ? $this->sanitizeString($item) : $item, $value);
    }

    private function handleError(string $message, \Exception $e, array $context = []): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        throw new ValidationException($message, 0, $e);
    }
}
