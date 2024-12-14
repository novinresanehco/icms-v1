<?php

namespace App\Core\Data\Models;

class BaseContent
{
    protected array $data;
    protected string $id;
    protected array $meta;

    public function __construct(array $data = [])
    {
        $this->data = $data;
        $this->meta = [
            'created_at' => time(),
            'updated_at' => time(),
            'version' => 1
        ];
    }

    public function getData(): array 
    {
        return $this->data;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'data' => $this->data,
            'meta' => $this->meta
        ];
    }
}

class Content extends BaseContent
{
    public function __construct(array $data)
    {
        parent::__construct($data);
        
        // Basic validation
        if (empty($data['title']) || empty($data['body'])) {
            throw new ValidationException();
        }
    }
}

class Media extends BaseContent 
{
    public function __construct(array $data)
    {
        parent::__construct($data);
        
        if (empty($data['file']) || empty($data['type'])) {
            throw new ValidationException();
        }
    }
}
