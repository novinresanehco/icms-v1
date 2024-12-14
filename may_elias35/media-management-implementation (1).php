<?php

namespace App\Core\Media;

use App\Core\Media\Contracts\MediaRepositoryInterface;
use App\Core\Media\Services\MediaProcessingService;
use App\Core\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

interface MediaServiceInterface {
    public function upload(UploadedFile $file): Media;
    public function delete(int $id): bool;
    public function find(int $id): ?Media;
    public function process(Media $media): Media;
}

class MediaService implements MediaServiceInterface {
    protected MediaRepositoryInterface $repository;
    protected MediaProcessingService $processor;

    public function __construct(
        MediaRepositoryInterface $repository,
        MediaProcessingService $processor
    ) {
        $this->repository = $repository;
        $this->processor = $processor;
    }

    public function upload(UploadedFile $file): Media {
        DB::beginTransaction();
        try {
            // Store file and create media record
            $path = Storage::putFile('media', $file);
            $media = $this->repository->create([
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'path' => $path,
                'size' => $file->getSize(),
                'status' => Media::STATUS_PENDING
            ]);

            // Process the media asynchronously
            $this->processor->dispatch($media);

            DB::commit();
            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            Storage::delete($path ?? '');
            throw new MediaUploadException($e->getMessage());
        }
    }

    public function delete(int $id): bool {
        DB::beginTransaction();
        try {
            $media = $this->repository->find($id);
            if (!$media) {
                throw new MediaNotFoundException("Media not found with ID: {$id}");
            }

            // Delete file from storage
            Storage::delete($media->path);
            
            // Delete record
            $this->repository->delete($id);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaDeletionException($e->getMessage());
        }
    }

    public function find(int $id): ?Media {
        return $this->repository->find($id);
    }

    public function process(Media $media): Media {
        try {
            return $this->processor->process($media);
        } catch (\Exception $e) {
            throw new MediaProcessingException($e->getMessage());
        }
    }
}

// Media Processing Pipeline
class MediaProcessingService {
    protected array $processors = [];
    
    public function addProcessor(MediaProcessorInterface $processor): self {
        $this->processors[] = $processor;
        return $this;
    }

    public function process(Media $media): Media {
        foreach ($this->processors as $processor) {
            if ($processor->supports($media)) {
                $media = $processor->process($media);
            }
        }
        return $media;
    }

    public function dispatch(Media $media): void {
        ProcessMediaJob::dispatch($media);
    }
}

// Interface for Media Processors
interface MediaProcessorInterface {
    public function supports(Media $media): bool;
    public function process(Media $media): Media;
}

// Image Processor Implementation
class ImageProcessor implements MediaProcessorInterface {
    public function supports(Media $media): bool {
        return str_starts_with($media->mime_type, 'image/');
    }

    public function process(Media $media): Media {
        // Process image (resize, optimize, create thumbnails)
        // Update media record with new metadata
        return $media;
    }
}

// Media Repository Implementation
class MediaRepository implements MediaRepositoryInterface {
    public function create(array $data): Media {
        return Media::create($data);
    }

    public function find(int $id): ?Media {
        return Media::find($id);
    }

    public function delete(int $id): bool {
        return Media::destroy($id) > 0;
    }

    public function update(int $id, array $data): Media {
        $media = $this->find($id);
        $media->update($data);
        return $media;
    }
}

// Media Model
class Media extends Model {
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'filename',
        'mime_type',
        'path',
        'size',
        'metadata',
        'status'
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer'
    ];
}

// Media Processing Job
class ProcessMediaJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Media $media;

    public function __construct(Media $media) {
        $this->media = $media;
    }

    public function handle(MediaProcessingService $processor): void {
        try {
            $this->media->update(['status' => Media::STATUS_PROCESSING]);
            
            $processed = $processor->process($this->media);
            
            $processed->update(['status' => Media::STATUS_COMPLETED]);
        } catch (\Exception $e) {
            $this->media->update([
                'status' => Media::STATUS_FAILED,
                'metadata' => array_merge(
                    $this->media->metadata ?? [],
                    ['error' => $e->getMessage()]
                )
            ]);
            
            throw $e;
        }
    }
}
