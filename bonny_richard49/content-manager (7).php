<?php

namespace App\Core\CMS;

use App\Core\Interfaces\ContentManagerInterface;
use App\Core\Security\SecurityManager;
use App\Core\System\StorageService;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\ContentException;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private StorageService $storage;
    private LoggerInterface $logger;
    private ValidationService $validator;
    private array $config;

    private const MAX_VERSIONS = 10;
    private const CACHE_TTL = 3600;
    private const BATCH_SIZE = 100;

    public function __construct(
        SecurityManager $security,
        StorageService $storage,
        LoggerInterface $logger,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->logger = $logger;
        $this->validator = $validator;
        $this->config = config('cms');
    }

    public function createContent(array $data, SecurityContext $context): Content
    {
        $this->validateOperation('create', $context);

        try {
            DB::beginTransaction();

            $validated = $this->validator->validateContent($data);
            $content = new Content($validated);
            
            $this->processMediaFiles($content, $data['media'] ?? []);
            $this->saveContent($content);
            $this->createVersion($content);
            
            DB::commit();
            
            $this->logger->info('Content created', [
                'id' => $content->getId(),
                'type' => $content->getType(),
                'user' => $context->getUser()->getId()
            ]);
            
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('Content creation failed', $e);
        }
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        $this->validateOperation('update', $context);

        try {
            DB::beginTransaction();

            $content = $this->findContent($id);
            $validated = $this->validator->validateContent($data);
            
            $content->update($validated);
            $this->processMediaFiles($content, $data['media'] ?? []);
            $this->saveContent($content);
            $this->createVersion($content);
            
            DB::commit();
            
            $this->logger->info('Content updated', [
                'id' => $content->getId(),
                'type' => $content->getType(),
                'user' => $context->getUser()->getId()
            ]);
            
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('Content update failed', $e);
        }
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        $this->validateOperation('delete', $context);

        try {
            DB::beginTransaction();

            $content = $this->findContent($id);
            $this->deleteMediaFiles($content);
            $this->deleteVersions($content);
            $content->delete();
            
            DB::commit();
            
            $this->logger->info('Content deleted', [
                'id' => $id,
                'user' => $context->getUser()->getId()
            ]);
            
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('Content deletion failed', $e);
        }
    }

    public function publishContent(int $id, SecurityContext $context): bool
    {
        $this->validateOperation('publish', $context);

        try {
            DB::beginTransaction();

            $content = $this->findContent($id);
            $content->setStatus('published');
            $content->setPublishedAt(now());
            $this->saveContent($content);
            
            DB::commit();
            
            $this->logger->info('Content published', [
                'id' => $id,
                'user' => $context->getUser()->getId()
            ]);
            
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('Content publication failed', $e);
        }
    }

    private function validateOperation(string $operation, SecurityContext $context): void
    {
        if (!$this->security->validateAccess($context)) {
            throw new ContentException('Access denied');
        }
    }

    private function findContent(int $id): Content
    {
        $content = Content::find($id);
        
        if (!$content) {
            throw new ContentException('Content not found');
        }
        
        return $content;
    }

    private function processMediaFiles(Content $content, array $media): void
    {
        foreach ($media as $file) {
            $path = $this->storage->putFile('media', $file);
            $content->attachMedia($path);
        }
    }

    private function deleteMediaFiles(Content $content): void
    {
        foreach ($content->getMedia() as $media) {
            $this->storage->delete($media->getPath());
        }
    }

    private function createVersion(Content $content): void
    {
        $version = new ContentVersion($content);
        $version->save();

        $this->cleanupOldVersions($content);
    }

    private function cleanupOldVersions(Content $content): void
    {
        $versions = $content->getVersions()
            ->sortByDesc('created_at')
            ->slice(self::MAX_VERSIONS);

        foreach ($versions as $version) {
            $version->delete();
        }
    }

    private function deleteVersions(Content $content): void
    {
        foreach ($content->getVersions() as $version) {
            $version->delete();
        }
    }

    private function handleError(string $message, \Exception $e): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new ContentException($message, 0, $e);
    }
}
