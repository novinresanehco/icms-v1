<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Content\Models\Content;
use App\Core\Content\Services\{
    ContentValidator,
    VersionManager,
    MediaManager
};
use Illuminate\Support\Facades\Cache;

class ContentManager
{
    private SecurityManager $security;
    private ContentValidator $validator;
    private VersionManager $versions;
    private MediaManager $media;
    
    public function __construct(
        SecurityManager $security,
        ContentValidator $validator,
        VersionManager $versions,
        MediaManager $media
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->versions = $versions;
        $this->media = $media;
    }

    public function createContent(array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            function($secureContext) use ($data) {
                // Validate content data
                $validatedData = $this->validator->validate($data);
                
                // Create content version
                $content = new Content($validatedData);
                $content->save();
                
                // Handle media attachments
                if (!empty($data['media'])) {
                    $this->media->attachToContent($content, $data['media']);
                }
                
                // Create initial version
                $this->versions->createVersion($content);
                
                // Clear relevant caches
                $this->clearCaches($content);
                
                return $content;
            },
            $context
        );
    }

    public function updateContent(int $id, array $data, array $context): Content
    {
        return $this->security->executeCriticalOperation(
            function($secureContext) use ($id, $data) {
                $content = Content::findOrFail($id);
                
                // Validate updates
                $validatedData = $this->validator->validate($data);
                
                // Create new version
                $this->versions->createVersion($content);
                
                // Update content
                $content->update($validatedData);
                
                // Update media
                if (isset($data['media'])) {
                    $this->media->syncWithContent($content, $data['media']);
                }
                
                // Clear caches
                $this->clearCaches($content);
                
                return $content;
            },
            $context
        );
    }

    public function publishContent(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            function($secureContext) use ($id) {
                $content = Content::findOrFail($id);
                
                // Verify publishable
                if (!$this->validator->canPublish($content)) {
                    throw new ContentException('Content not publishable');
                }
                
                $content->publish();
                $this->clearCaches($content);
                
                return true;
            },
            $context
        );
    }

    protected function clearCaches(Content $content): void
    {
        Cache::tags(['content', "content:{$content->id}"])->flush();
    }
}

class ContentException extends \Exception {}
