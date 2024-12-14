<?php

namespace App\Core\Media\Repositories;

use App\Core\Media\Models\Media;
use App\Core\Repository\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaRepository extends BaseRepository
{
    public function model(): string
    {
        return Media::class;
    }

    public function create(array $data): Media
    {
        return Media::create($data);
    }

    public function update(Media $media, array $data): Media
    {
        $media->update($data);
        return $media->fresh();
    }

    public function delete(Media $media): bool
    {
        return $media->delete();
    }

    public function findByType(string $type, array $filters = []): Collection
    {
        $query = Media::query();

        if ($type === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($type === 'video') {
            $query->where('mime_type', 'like', 'video/%');
        } else {
            $query->