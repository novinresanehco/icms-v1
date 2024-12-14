<?php

namespace App\Core\Cache\Services;

use App\Core\Cache\Exceptions\CacheValidationException;

class CacheValidator
{
    private const MAX_KEY_LENGTH = 250;
    private const KEY_PATTERN = '/^[a-zA-Z0-9:._-]+$/';

    public function validateKey(string $key): void
    {
        if (empty($key)) {
            throw new CacheValidationException('Cache key cannot be empty');
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new CacheValidationException(
                'Cache key length cannot exceed ' . self::MAX_KEY_LENGTH . ' characters'
            );
        }

        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new CacheValidationException(
                'Cache key can only contain alphanumeric characters, colons, dots, underscores and hyphens'
            );
        }
    }

    public function validateTag(string $tag): void
    {
        if (empty($tag)) {
            throw new CacheValidationException('Cache tag cannot be empty');
        }

        if (!preg_match(self::KEY_PATTERN, $tag)) {
            throw new CacheValidationException(
                'Cache tag can only contain alphanumeric characters, colons, dots, underscores and hyphens'
            );
        }
    }
}
