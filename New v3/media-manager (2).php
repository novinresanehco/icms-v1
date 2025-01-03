<?php

namespace App\Core\Media;

class MediaManager implements MediaManagerInterface 
{
    private StorageManager $storage;
    private SecurityManager $security;
    private CacheManager $cache;
    private DatabaseManager $database;
    private QueueManager $queue;
    private AuditService $audit;
    private MetricsCollector $metrics;
    private ValidationService $validator;

    public function __construct(
        StorageManager $storage,
        SecurityManager $security,
        CacheManager $cache,
        DatabaseManager $database,
        QueueManager $queue,
        AuditService $audit,
        MetricsCollector $metrics,
        ValidationService $validator
    ) {
        $this->storage = $storage;
        $this->security = $security;
        $this->cache = $cache;
        $this->database = $database;
        $this->queue = $queue;
        $this->audit = $audit;
        $this->metrics = $metrics;
        $this->validator = $validator;
    }

    public function upload(MediaUploadRequest $request): Media
    {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();

            $this->validateUploadRequest($request);
            $this->security->validateAccess($request->getUser(), 'media.upload');

            $media = $this->processUpload($request);
            
            $this->optimizeMedia($media);
            $this->processVariants($media);
            $this->updateMetadata($media);
            
            $this->database->save($media);
            $this->cache->invalidateMediaCache($media);
            
            $this->audit->logMediaUpload($media, $request->getUser());
            $this->metrics->recordMediaOperation('upload', microtime(true) - $startTime);

            DB::commit();
            
            $this->queue->dispatch(new MediaProcessingJob($media));
            
            return $media;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'upload', $request);
            throw $e;
        }
    }

    public function process(Media $media, ProcessingOptions $options): Media
    {
        try {
            DB::beginTransaction();

            $this->validateProcessingOptions($options);
            
            $processed = $this->applyProcessing($media, $options);
            $this->validateProcessedMedia($processed);
            
            $this->storage->storeProcessedMedia($processed);
            $this->updateMediaRecord($processed);
            
            $this->cache->invalidateMediaCache($media);
            $this->audit->logMediaProcessing($media, $options);

            DB::commit();
            
            return $processed;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'process', $media);
            throw $e;
        }
    }

    public function distribute(Media $media, DistributionRequest $request): void
    {
        try {
            DB::beginTransaction();

            $this->validateDistributionRequest($request);
            $this->security->validateAccess($request->getUser(), 'media.distribute');

            foreach ($request->getTargets() as $target) {
                $this->distributeToTarget($media, $target);
            }

            $this->updateDistributionStatus($media, $request);
            $this->audit->logMediaDistribution($media, $request);

            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'distribute', $request);
            throw $e;
        }
    }

    public function delete(string $id, User $user): void
    {
        try {
            DB::beginTransaction();

            $media = $this->findMedia($id);
            $this->security->validateAccess($user, 'media.delete');

            $this->storage->deleteMedia($media);
            $this->cleanupMediaData($media);
            $this->database->delete($media);
            
            $this->cache->invalidateMediaCache($media);
            $this->audit->logMediaDeletion($media, $user);

            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'delete', $id);
            throw $e;
        }
    }

    private function processUpload(MediaUploadRequest $request): Media
    {
        $file = $request->getFile();
        $this->validateMediaFile($file);

        $media = new Media();
        $media->setOriginalName($file->getClientOriginalName());
        $media->setMimeType($file->getMimeType());
        $media->setSize($file->getSize());
        $media->setUploadedBy($request->getUser());

        $path = $this->storage->storeMedia($file);
        $media->setPath($path);

        return $media;
    }

    private function optimizeMedia(Media $media): void
    {
        $optimizer = $this->getOptimizer($media->getMimeType());
        $optimized = $optimizer->optimize($media);
        
        $this->storage->storeOptimizedMedia($optimized);
        $media->setOptimizedPath($optimized->getPath());
        $media->setOptimizedSize($optimized->getSize());
    }

    private function processVariants(Media $media): void
    {
        $variants = $this->generateVariants($media);
        
        foreach ($variants as $variant) {
            $this->storage->storeVariant($variant);
            $this->database->saveVariant($variant);
        }
        
        $media->setVariants($variants);
    }

    private function updateMetadata(Media $media): void
    {
        $metadata = $this->extractMetadata($media);
        $media->setMetadata($metadata);
        
        if ($metadata->hasGeolocation()) {
            $this->security->validateGeolocationAccess($media);
        }
    }

    private function distributeToTarget(Media $media, DistributionTarget $target): void
    {
        $distributor = $this->getDistributor($target->getType());
        $result = $distributor->distribute($media, $target);
        
        $this->updateDistributionResult($media, $target, $result);
    }

    private function validateMediaFile(UploadedFile $file): void
    {
        if (!$this->validator->validateMediaFile($file)) {
            throw new InvalidMediaException('Invalid media file');
        }

        if (!$this->security->validateMediaContent($file)) {
            throw new SecurityException('Media content validation failed');
        }
    }

    private function handleOperationFailure(\Exception $e, string $operation, $context): void
    {
        $this->audit->logMediaOperationFailure($operation, $context, $e);
        $this->metrics->recordMediaOperationFailure($operation);
    }

    private function getOptimizer(string $mimeType): MediaOptimizer
    {
        return $this->optimizerFactory->createOptimizer($mimeType);
    }

    private function getDistributor(string $type): MediaDistributor
    {
        return $this->distributorFactory->createDistributor($type);
    }
}
