<?php

namespace App\Core\Sitemap\Services;

use App\Core\Sitemap\Exceptions\SitemapValidationException;

class SitemapValidator
{
    private const ALLOWED_FREQUENCIES = [
        'always',
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'yearly',
        'never'
    ];

    public function validateUrl(string $url, array $options = []): void
    {
        $this->validateUrlFormat($url);
        $this->validateChangeFreq($options['changefreq'] ?? null);
        $this->validatePriority($options['priority'] ?? null);
        $this->validateLastMod($options['lastmod'] ?? null);
    }

    protected function validateUrlFormat(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new SitemapValidationException("Invalid URL format: {$url}");
        }
    }

    protected function validateChangeFreq(?string $frequency): void
    {
        if ($frequency !== null && !in_array($frequency, self::ALLOWED_FREQUENCIES)) {
            throw new SitemapValidationException(
                "Invalid change frequency. Allowed values: " . implode(', ', self::ALLOWED_FREQUENCIES)
            );
        }
    }

    protected function validatePriority(?float $priority): void
    {
        if ($priority !== null && ($priority < 0 || $priority > 1)) {
            throw new SitemapValidationException(
                "Priority must be between 0.0 and 1.0"
            );
        }
    }

    protected function validateLastMod(?string $lastmod): void
    {
        if ($lastmod !== null && !strtotime($lastmod)) {
            throw new SitemapValidationException(
                "Invalid lastmod date format"
            );
        }
    }
}
