<?php

namespace App\Core\Services;

use App\Core\Security\SecurityManager;
use App\Core\Repositories\{ContentRepository, MediaRepository};
use App\Core\Interfaces\CmsServiceInterface;
use Illuminate\Support\Facades\{Cache, Log};

class ContentService implements CmsServiceInterface 
{
    protected ContentRepository $content;
    protected MediaRepository $media;
    protected SecurityManager $security;
    protected array $cacheConfig;

    public function __construct(
        ContentRepository $content,
        MediaRepository $media,
        SecurityManager $security
    ) {
        $this->content = $content;
        $this->media = $media;
        $this->security = $security;
        $this->cacheConfig = config('cache.content');
    }

    public function create(array $data): array
    {
        return $this->security->executeSecure(function() use ($data) {
            // Validate and create content
            $content = $this->content->create($data);
            
            // Handle media attachments
            if (isset($data['media'])) {
                $this->attachMedia($content, $data['media']);
            }

            // Clear relevant caches
            $this->clearContentCaches($content->id);
            
            return $content->toArray();
        }, ['action' => 'content.create']);
    }

    public function update(int $id, array $data): array
    {
        return $this->security->executeSecure(function() use ($id, $data) {
            // Validate and update content
            $content = $this->content->update($id, $data);
            
            // Update media attachments
            if (isset($data['media'])) {
                $this->syncMedia($content, $data['media']);
            }

            // Clear relevant caches
            $this->clearContentCaches($id);
            
            return $content->toArray();
        }, ['action' => 'content