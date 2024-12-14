<?php

namespace App\Core\Notification\Analytics\Channels;

use App\Core\Notification\Analytics\Contracts\{
    AlertChannelInterface,
    AlertChannelConfigurationInterface,
    AlertChannelFormatterInterface,
    AlertChannelRateLimiterInterface,
    AlertChannelRetryInterface
};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class SlackAlertChannel implements 
    AlertChannelInterface,
    AlertChannelConfigurationInterface,
    AlertChannelFormatterInterface,
    AlertChannelRateLimiterInterface,
    AlertChannelRetryInterface
{
    private array $config;
    private const CACHE_PREFIX = 'slack_alert_channel:';
    private const MAX_ATTEMPTS = 3;
    private const RATE_LIMIT_KEY = 'slack_rate_limit';
    private const RATE_LIMIT_MINUTES = 1;
    private const RATE_LIMIT_ATTEMPTS = 30;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function send(string $message, array $data = [], array $options = []): array
    {
        if ($this->isRateLimited(self::RATE_LIMIT_KEY)) {
            throw new Exception('Rate limit exceeded for Slack channel');
        }

        $payload = $this->formatMessage($message, $data, $options);
        $attempts = 0;

        do {
            try {
                $response = Http::post($this->config['webhook_url'], $payload);
                
                if ($response->successful()) {
                    $this->hit(self::RATE_LIMIT_KEY);
                    return [
                        'success' => true,
                        'message_ts' => $response->json('ts'),
                        'channel' => $response->json('channel')
                    ];
                }

                throw new Exception("Slack API error: {$response->body()}");
            } catch (Exception $e) {
                $attempts++;
                
                if (!$this->shouldRetry($e, $attempts)) {
                    throw $e;
                }

                sleep($this->getRetryDelay($attempts));
            }
        } while ($attempts < $this->getMaxAttempts());

        throw new Exception("Failed to send Slack alert after {$attempts} attempts");
    }

    public function isAvailable(): bool
    {
        return !empty($this->config['webhook_url']) && 
               Http::get($this->config['webhook_url'])->successful();
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function validateData(array $data): bool
    {
        return isset($data['message']) && !empty($data['message']);
    }

    public function getRequiredConfig(): array
    {
        return ['webhook_url'];
    }

    public function getOptionalConfig(): array
    {
        return [
            'default_channel',
            'username',
            'icon_emoji',
            'icon_url'
        ];
    }

    public function validateConfig(array $config): bool
    {
        foreach ($this->getRequiredConfig() as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }
        return true;
    }

    public function getDefaultConfig(): array
    {
        return [
            'username' => 'Alert Bot',
            'icon_emoji' => ':warning:',
            'default_channel' => '#alerts'
        ];
    }

    public function formatMessage(string $message, array $data = [], array $options = []): array
    {
        $severity = $options['severity'] ?? 'info';
        $color = $this->getSeverityColor($severity);

        $attachment = [
            'color' => $color,
            'text' => $message,
            'ts' => time(),
            'fields' => $this->formatFields($data),
            'footer' => $options['metric_name'] ?? 'Alert System'
        ];

        return [
            'text' => $this->formatHeaderText($severity, $options),
            'attachments' => [$attachment],
            'channel' => $options['channel'] ?? $this->config['default_channel'],
            'username' => $this->config['username'],
            'icon_emoji' => $this->config['icon_emoji']
        ];
    }

    public function formatError(\Throwable $error, array $context = []): string
    {
        return sprintf(
            "*Error sending Slack alert*\nError: %s\nContext: %s",
            $error->getMessage(),
            json_encode($context)
        );
    }

    public function isRateLimited(string $key): bool
    {
        $attempts = Cache::get($this->getRateLimitKey($key), 0);
        return $attempts >= self::RATE_LIMIT_ATTEMPTS;
    }

    public function hit(string $key): void
    {
        $cacheKey = $this->getRateLimitKey($key);
        $attempts = Cache::get($cacheKey, 0) + 1;
        
        Cache::put(
            $cacheKey,
            $attempts,
            now()->addMinutes(self::RATE_LIMIT_MINUTES)
        );
    }

    public function remaining(string $key): int
    {
        $attempts = Cache::get($this->getRateLimitKey($key), 0);
        return max(0, self::RATE_LIMIT_ATTEMPTS - $attempts);
    }

    public function reset(string $key): void
    {
        Cache::forget($this->getRateLimitKey($key));
    }

    public function shouldRetry(\Throwable $error, int $attempts): bool
    {
        if ($attempts >= $this->getMaxAttempts()) {
            return false;
        }

        // Retry on network errors or 5xx responses
        return $error instanceof \Illuminate\Http\Client\ConnectionException ||
               (method_exists($error, 'response') && 
                $error->response && 
                $error->response->status() >= 500);
    }

    public function getRetryDelay(int $attempts): int
    {
        // Exponential backoff: 2^n seconds
        return pow(2, $attempts);
    }

    public function getMaxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    private function getRateLimitKey(string $key): string
    {
        return self::CACHE_PREFIX . $key;
    }

    private function getSeverityColor(string $severity): string
    {
        return match($severity) {
            'critical' => '#FF0000',
            'warning' => '#FFA500',
            'info' => '#0000FF',
            default => '#808080'
        };
    }

    private function formatFields(array $data): array
    {
        $fields = [];
        
        foreach ($data as $key => $value) {
            $fields[] = [
                'title' => ucfirst(str_replace('_', ' ', $key)),
                'value' => is_array($value) ? json_encode($value) : (string)$value,
                'short' => strlen((string)$value) < 50
            ];
        }

        return $fields;
    }

    private function formatHeaderText(string $severity, array $options): string
    {
        $emoji = match($severity) {
            'critical' => ':red_circle:',
            'warning' => ':warning:',
            'info' => ':information_source:',
            default => ':bell:'
        };

        return sprintf(
            "%s *%s Alert*: %s",
            $emoji,
            ucfirst($severity),
            $options['metric_name'] ?? 'System Alert'
        );
    }
}
