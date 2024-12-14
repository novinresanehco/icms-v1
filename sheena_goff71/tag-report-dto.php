<?php

namespace App\Core\Tag\DTOs;

use Illuminate\Support\Collection;

class TagReportData
{
    /**
     * @var int
     */
    public int $totalTags;

    /**
     * @var int
     */
    public int $activeTagsCount;

    /**
     * @var int
     */
    public int $unusedTagsCount;

    /**
     * @var Collection
     */
    public Collection $topTags;

    /**
     * @var Collection
     */
    public Collection $tagUsageOverTime;

    /**
     * @var array
     */
    public array $contentDistribution;

    /**
     * Convert the DTO to an array.
     */
    public function toArray(): array
    {
        return [
            'total_tags' => $this->totalTags,
            'active_tags_count' => $this->activeTagsCount,
            'unused_tags_count' => $this->unusedTagsCount,
            'top_tags' => $this->topTags->toArray(),
            'tag_usage_over_time' => $this->tagUsageOverTime->toArray(),
            'content_distribution' => $this->contentDistribution
        ];
    }

    /**
     * Calculate tag utilization rate.
     */
    public function getUtilizationRate(): float
    {
        if ($this->totalTags === 0) {
            return 0.0;
        }

        return round(($this->activeTagsCount / $this->totalTags) * 100, 2);
    }

    /**
     * Get health score.
     */
    public function getHealthScore(): float
    {
        $utilizationScore = $this->getUtilizationRate();
        $unusedScore = 100 - (($this->unusedTagsCount / $this->totalTags) * 100);
        
        return round(($utilizationScore + $unusedScore) / 2, 2);
    }
}
