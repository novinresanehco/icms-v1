<?php

namespace App\Core\Notification\Analytics\Contracts;

interface AlertChannelInterface
{
    /**
     * Send an alert through the channel
     *
     * @param string $message
     * @param array $data
     * @param array $options
     * @return array
     */
    public function send(string $message, array $data = [], array $options = []): array;

    /**
     * Check if the channel is available
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get channel configuration
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * Validate channel specific data
     *
     * @param array $data
     * @return bool
     */
    public function validateData(array $data): bool;
}

interface AlertChannelConfigurationInterface
{
    /**
     * Get required configuration fields
     *
     * @return array
     */
    public function getRequiredConfig(): array;

    /**
     * Get optional configuration fields
     *
     * @return array
     */
    public function getOptionalConfig(): array;

    /**
     * Validate channel configuration
     *
     * @param array $config
     * @return bool
     */
    public function validateConfig(array $config): bool;

    /**
     * Get default configuration values
     *
     * @return array
     */
    public function getDefaultConfig(): array;
}

interface AlertChannelFormatterInterface
{
    /**
     * Format message for the channel
     *
     * @param string $message
     * @param array $data
     * @param array $options
     * @return mixed
     */
    public function formatMessage(string $message, array $data = [], array $options = []): mixed;

    /**
     * Format error message
     *
     * @param \Throwable $error
     * @param array $context
     * @return string
     */
    public function formatError(\Throwable $error, array $context = []): string;
}

interface AlertChannelRateLimiterInterface
{
    /**
     * Check if the channel is rate limited
     *
     * @param string $key
     * @return bool
     */
    public function isRateLimited(string $key): bool;

    /**
     * Record a channel hit
     *
     * @param string $key
     * @return void
     */
    public function hit(string $key): void;

    /**
     * Get remaining attempts
     *
     * @param string $key
     * @return int
     */
    public function remaining(string $key): int;

    /**
     * Reset rate limiter
     *
     * @param string $key
     * @return void
     */
    public function reset(string $key): void;
}

interface AlertChannelRetryInterface
{
    /**
     * Should retry the alert
     *
     * @param \Throwable $error
     * @param int $attempts
     * @return bool
     */
    public function shouldRetry(\Throwable $error, int $attempts): bool;

    /**
     * Get retry delay in seconds
     *
     * @param int $attempts
     * @return int
     */
    public function getRetryDelay(int $attempts): int;

    /**
     * Get max retry attempts
     *
     * @return int
     */
    public function getMaxAttempts(): int;
}
