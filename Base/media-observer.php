<?php

namespace App\Core\Observers;

use App\Core\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class MediaObserver
{
    public function deleted(Media $media): void
    {
        Storage::disk('public')->delete($media->path);
        Cache::tags(['media'])->forget("media.{$media->id}");
    }

    public function created(Media $media): void
    {
        Cache::tags(['media'])->forget('media.all');
    }

    public function updated(Media $media): void
    {
        Cache::tags(['media'])->forget("media.{$media->id}");
    }
}
