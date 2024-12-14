<?php

namespace App\Core\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class BaseEvent
{
    use Dispatchable, SerializesModels;

    public Model $model;
    public array $data;
    public ?int $userId;

    public function __construct(Model $model, array $data = [])
    {
        $this->model = $model;
        $this->data = $data;
        $this->userId = auth()->id();
    }
}

class ContentCreated extends BaseEvent
{
}

class ContentUpdated extends BaseEvent
{
    public array $changes;

    public function __construct(Model $model, array $changes)
    {
        parent::__construct($model);
        $this->changes = $changes;
    }
}

class ContentDeleted extends BaseEvent
{
}

class ContentPublished extends BaseEvent
{
    public \DateTime $publishedAt;

    public function __construct(Model $model)
    {
        parent::__construct($model);
        $this->publishedAt = $model->published_at;
    }
}

class ContentArchived extends BaseEvent
{
    public \DateTime $archivedAt;

    public function __construct(Model $model)
    {
        parent::__construct($model);
        $this->archivedAt = $model->archived_at;
    }
}

class ContentRestored extends BaseEvent
{
}

class ContentVersionCreated extends BaseEvent
{
    public int $version;
    public string $changelog;

    public function __construct(Model $model, int $version, string $changelog)
    {
        parent::__construct($model);
        $this->version = $version;
        $this->changelog = $changelog;
    }
}

class MediaUploaded extends BaseEvent
{
    public string $path;
    public string $type;
    public int $size;

    public function __construct(Model $model, string $path, string $type, int $size)
    {
        parent::__construct($model);
        $this->path = $path;
        $this->type = $type;
        $this->size = $size;
    }
}

class TagsUpdated extends BaseEvent
{
    public array $oldTags;
    public array $newTags;

    public function __construct(Model $model, array $oldTags, array $newTags)
    {
        parent::__construct($model);
        $this->oldTags = $oldTags;
        $this->newTags = $newTags;
    }
}

class MetadataUpdated extends BaseEvent
{
    public array $oldMetadata;
    public array $newMetadata;

    public function __construct(Model $model, array $oldMetadata, array $newMetadata)
    {
        parent::__construct($model);
        $this->oldMetadata = $oldMetadata;
        $this->newMetadata = $newMetadata;
    }
}
