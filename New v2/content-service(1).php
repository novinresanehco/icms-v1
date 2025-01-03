<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface
{
    private ContentRepository $repository;
    private VersionManager $versions;
    private MediaManager $media;
    private SecurityService $security;
    private ValidationService $validator;
    private EventManager $events;

    public function __construct(
        ContentRepository $repository,
        VersionManager $versions,
        MediaManager $media,
        SecurityService $security,
        ValidationService $validator,
        EventManager $events
    ) {
        $this->repository = $repository;
        $this->versions = $versions;
        $this->media = $media;
        $this->security = $security;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function create(array $data, ?User $user = null): Content
    {
        return $this->executeContentOperation(function() use ($data, $user) {
            // Validate content data
            $validated = $this->validator->validate($data, $this->getValidationRules());
            
            // Create content
            $content = $this->repository->create($validated);
            
            // Handle media attachments
            if (isset($validated['media'])) {
                $this->handleMediaAttachments($content, $validated['media']);
            }
            
            // Create initial version
            $this->versions->createVersion($content, $user);
            
            // Dispatch creation event
            $this->events->dispatch(new ContentCreated($content));
            
            return $content;
        });
    }

    public function update(int $id, array $data, ?User $user = null): Content
    {
        return $this->executeContentOperation(function() use ($id, $data, $user) {
            $content = $this->repository->findOrFail($id);
            
            // Validate update data
            $validated = $this->validator->validate($data, $this->getValidationRules());
            
            // Create new version before update
            $this->versions->createVersion($content, $user);
            
            // Update content
            $content = $this->repository->update($id, $validated);
            
            // Handle media changes
            if (isset($validated['media'])) {
                $this->handleMediaAttachments($content, $validated['media']);
            }
            
            // Dispatch update event
            $this->events->dispatch(new ContentUpdated($content));
            
            return $content;
        });
    }

    public function publish(int $id, ?User $user = null): Content
    {
        return $this->executeContentOperation(function() use ($id, $user) {
            $content = $this->repository->findOrFail($id);
            
            // Validate publication
            $this->validatePublication($content);
            
            // Create published version
            $this->versions->createVersion($content, $user, 'published');
            
            // Update content status
            $content = $this->repository->update($id, ['status' => 'published']);
            
            // Dispatch publish event
            $this->events->dispatch(new ContentPublished($content));
            
            return $content;
        });
    }

    public function delete(int $id, ?User $user = null): void
    {
        $this->executeContentOperation(function() use ($id, $user) {
            $content = $this->repository->findOrFail($id);
            
            // Create deletion version
            $this->versions->createVersion($content, $user, 'deleted');
            
            // Delete content
            $this->repository->delete($id);
            
            // Clean up media
            $this->media->cleanupContentMedia($content);
            
            // Dispatch deletion event
            $this->events->dispatch(new ContentDeleted($content));
        });
    }

    protected function executeContent