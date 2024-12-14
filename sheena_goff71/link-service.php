<?php

namespace App\Core\Link\Services;

use App\Core\Link\Models\Link;
use App\Core\Link\Repositories\LinkRepository;
use Illuminate\Support\Str;

class LinkService
{
    public function __construct(
        private LinkRepository $repository,
        private LinkValidator $validator,
        private LinkAnalytics $analytics
    ) {}

    public function create(string $url, array $options = []): Link
    {
        $this->validator->validateUrl($url);

        $link = $this->repository->create([
            'original_url' => $url,
            'short_code' => $options['code'] ?? $this->generateCode(),
            'expires_at' => $options['expires_at'] ?? null,
            'max_clicks' => $options['max_clicks'] ?? null,
            'is_active' => true
        ]);

        if (!empty($options['tags'])) {
            $this->repository->attachTags($link, $options['tags']);
        }

        return $link;
    }

    public function getUrl(string $code): string
    {
        $link = $this->repository->findByCode($code);

        if (!$link || !$link->isActive()) {
            throw new LinkException('Link not found or inactive');
        }

        if ($link->isExpired()) {
            throw new LinkException('Link has expired');
        }

        if ($link->hasReachedMaxClicks()) {
            throw new LinkException('Link has reached maximum clicks');
        }

        $this->analytics->recordClick($link);
        return $link->original_url;
    }

    public function deactivate(Link $link): bool
    {
        return $this->repository->update($link, ['is_active' => false]);
    }

    public function reactivate(Link $link): bool
    {
        return $this->repository->update($link, ['is_active' => true]);
    }

    public function setExpiry(Link $link, \DateTime $expiryDate): Link
    {
        return $this->repository->update($link, ['expires_at' => $expiryDate]);
    }

    public function setMaxClicks(Link $link, int $maxClicks): Link
    {
        return $this->repository->update($link, ['max_clicks' => $maxClicks]);
    }

    public function getStats(Link $link): array
    {
        return $this->analytics->getStats($link);
    }

    public function search(array $filters = []): Collection
    {
        return $this->repository->getWithFilters($filters);
    }

    protected function generateCode(int $length = 6): string
    {
        do {
            $code = Str::random($length);
        } while ($this->repository->findByCode($code));

        return $code;
    }
}
