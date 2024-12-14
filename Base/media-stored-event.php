<?php

namespace App\Events;

use App\Models\Media;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaStored
{
    use Dispatchable, SerializesModels;

    public function __construct(public Media $media) {}
}
