<?php

namespace App\Core\Link\Repositories;

use App\Core\Link\Models\Link;
use Illuminate\Support\Collection;

class LinkRepository
{
    public function create(array $data): Link
    {
        return Link::create($data);
    }

    public function update(Link $link, array $data): Link
    {
        $link->update($data);
        return $link->fresh();
    }

    public function findByCode(string $code): ?Link
    {
        return Link::byCode($code)->first();
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = Link::query();

        if (!empty($filters['active'])) {
            $query->active();
        }

        if (!empty($filters['expired'])) {
            $query->expired();
        }

        if (!empty($filters['url'])) {
            $query->where('original_url', 'like', "%{$filters['url']}%");
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->get();
    }

    public function attachTags(Link $link, array $tags): void
    {
        $link->tags()->sync($tags);
    }
}
