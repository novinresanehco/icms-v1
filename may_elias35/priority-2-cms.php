<?php namespace App\Core\CMS;

class ContentManager
{
    private SecurityManager $security;
    private Repository $repo;
    private CacheManager $cache;

    public function create(array $data): Content
    {
        return $this->security->validateOperation(
            new CreateContentOperation($data, $this->repo)
        );
    }
    
    public function update(string $id, array $data): Content
    {
        return $this->security->validateOperation(
            new UpdateContentOperation($id, $data, $this->repo) 
        );
    }
}

class MediaManager
{
    private SecurityManager $security;
    private StorageManager $storage;

    public function store(UploadedFile $file): Media
    {
        return $this->security->validateOperation(
            new StoreMediaOperation($file, $this->storage)
        );
    }
}

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;

    public function render(string $template, array $data): string
    {
        return $this->security->validateOperation(
            new RenderTemplateOperation($template, $data, $this->cache)
        );
    }
}
