<?php

namespace App\Core\Link\Services;

use App\Core\Link\Exceptions\LinkValidationException;

class LinkValidator
{
    public function validateUrl(string $url): void
    {
        if (empty($url)) {
            throw new LinkValidationException('URL cannot be empty');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new LinkValidationException('Invalid URL format');
        }

        $this->validateDomain($url);
        $this->validateProtocol($url);
    }

    protected function validateDomain(string $url): void
    {
        $domain = parse_url($url, PHP_URL_HOST);
        $blockedDomains = config('link.blocked_domains', []);

        if (in_array($domain, $blockedDomains)) {
            throw new LinkValidationException('Domain is blocked');
        }
    }

    protected function validateProtocol(string $url): void
    {
        $protocol = parse_url($url, PHP_URL_SCHEME);
        $allowedProtocols = ['http', 'https'];

        if (!in_array($protocol, $allowedProtocols)) {
            throw new LinkValidationException('Invalid protocol');
        }
    }
}
