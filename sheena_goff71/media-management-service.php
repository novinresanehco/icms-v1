namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage, Event};

class MediaManagementService implements MediaManagementInterface
{
    private SecurityManager $security;
    private MediaRepository $repository;
    private MediaValidator $validator;
    private MediaProcessor $processor;
    private MediaOptimizer $optimizer;
    private AuditLogger $logger;
    private StorageConfig $config;

    public function __construct(
        SecurityManager $security,
        MediaRepository $repository,
        MediaValidator $validator,
        MediaProcessor $processor,
        MediaOptimizer $optimizer,
        AuditLogger $logger,
        StorageConfig $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->optimizer = $optimizer;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function upload(UploadedFile $file, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(
            new UploadMediaOperation($file),
            $context,
            function() use ($file) {
                $this->validator->validateUpload($file);
                
                return DB::transaction(function() use ($file) {
                    $media = $this->repository->create([
                        'name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'extension' => $file->getClientOriginalExtension()
                    ]);

                    $path = $this->storeFile($file, $media);
                    $media->path = $path;
                    $media->save();

                    $this->processor->process($media);
                    $this->optimizer->optimize($media);
                    $this->logger->logMediaUpload($media);
                    
                    Event::dispatch(new MediaEvent('uploaded', $media));
                    
                    return $media;
                });
            }
        );
    }

    public function delete(int $id, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new DeleteMediaOperation($id),
            $context,
            function() use ($id) {
                $media = $this->repository->findOrFail($id);
                
                DB::transaction(function() use ($media) {
                    Storage::delete($media->path);
                    $this->deleteVariants($media);
                    $this->repository->delete($media->id);
                    $this->logger->logMediaDeletion($media);
                    
                    Event::dispatch(new MediaEvent('deleted', $media));
                });
            }
        );
    }

    public function generateVariant(int $id, string $variant, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(
            new GenerateVariantOperation($id, $variant),
            $context,
            function() use ($id, $variant) {
                $media = $this->repository->findOrFail($id);
                $config = $this->config->getVariantConfig($variant);
                
                return DB::transaction(function() use ($media, $variant, $config) {
                    $variantMedia = $this->processor->generateVariant($media, $variant, $config);
                    $this->optimizer->optimize($variantMedia);
                    $this->logger->logVariantGeneration($media, $variant);
                    
                    Event::dispatch(new MediaEvent('variant_generated', $variantMedia));
                    
                    return $variantMedia;
                });
            }
        );
    }

    public function updateMetadata(int $id, array $metadata, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(
            new UpdateMetadataOperation($id, $metadata),
            $context,
            function() use ($id, $metadata) {
                $media = $this->repository->findOrFail($id);
                $validated = $this->validator->validateMetadata($metadata);
                
                return DB::transaction(function() use ($media, $validated) {
                    $updated = $this->repository->updateMetadata($media->id, $validated);
                    $this->logger->logMetadataUpdate($updated);
                    
                    Event::dispatch(new MediaEvent('metadata_updated', $updated));
                    
                    return $updated;
                });
            }
        );
    }

    private function storeFile(UploadedFile $file, Media $media): string
    {
        $directory = $this->config->getStorageDirectory($media);
        $filename = $this->generateSecureFilename($file);
        
        return $file->storeAs(
            $directory,
            $filename,
            ['disk' => $this->config->getStorageDisk()]
        );
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            time(),
            hash('sha256', $file->getClientOriginalName()),
            $file->getClientOriginalExtension()
        );
    }

    private function deleteVariants(Media $media): void
    {
        $variants = $this->repository->getVariants($media->id);
        
        foreach ($variants as $variant) {
            Storage::delete($variant->path);
            $this->repository->delete($variant->id);
        }
    }

    public function validateMediaSecurity(Media $media): void
    {
        $this->validator->validateMimeType($media);
        $this->validator->validateFileContent($media);
        $this->validator->validateMetadata($media->metadata);
        $this->validator->validateSize($media);
    }

    private function processingRequired(Media $media): bool
    {
        return in_array($media->mime_type, $this->config->getProcessableTypes());
    }

    private function optimizationRequired(Media $media): bool
    {
        return in_array($media->mime_type, $this->config->getOptimizableTypes());
    }
}
