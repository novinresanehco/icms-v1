<?php

namespace App\Core\Link\Services;

use App\Core\Link\Models\Link;
use App\Core\Link\Models\LinkClick;

class LinkAnalytics
{
    public function recordClick(Link $link): void
    {
        LinkClick::create([
            'link_id' => $link->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'referer' => request()->header('referer')
        ]);
    }

    public function getStats(Link $link): array
    {
        return [
            'total_clicks' => $link->getClickCount(),
            'unique_visitors' => $this->getUniqueVisitors($link),
            'referrers' => $this->getReferrers($link),
            'browsers' => $this->getBrowserStats($link),
            'countries' => $this->getCountryStats($link),
            'click_times' => $this->getClickTimes($link)
        ];
    }

    protected function getUniqueVisitors(Link $link): int
    {
        return $link->clicks()
                   ->distinct('ip_address')
                   ->count('ip_address');
    }

    protected function getReferrers(Link $link): array
    {
        return $link->clicks()
                   ->whereNotNull('referer')
                   ->selectRaw('referer, count(*) as count')
                   ->groupBy('referer')
                   ->pluck('count', 'referer')
                   ->toArray();
    }

    protected function getBrowserStats(Link $link): array
    {
        return $link->clicks()
                   ->selectRaw('user_agent, count(*) as count')
                   ->groupBy('user_agent')
                   ->pluck('count', 'user_agent')
                   ->toArray();
    }

    protected function getCountryStats(Link $link): array
    {
        // Implement IP to country lookup
        return [];
    }

    protected function getClickTimes(Link $link): array
    {
        return $link->clicks()
                   ->selectRaw('DATE(created_at) as date, count(*) as count')
                   ->groupBy('date')
                   ->pluck('count', 'date')
                   ->toArray();
    }
}
