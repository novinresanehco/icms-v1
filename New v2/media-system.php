<?php
namespace App\Core\Media;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private MediaRepository $media;
    private StorageService $storage;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        MediaRepository $media,
        StorageService $storage,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->media = $media;
        $this->storage = $storage;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function upload(UploadedFile $file, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(
            new UploadMediaOperation(
                $file,
                $this->media,
                $this->storage,
                $this->validator,
                $this->audit
            ),
            $context
        );
    }

    public function download(int $id, SecurityContext $context): MediaDownload
    {
        return $this->security->executeCriticalOperation(
            new DownloadMediaOperation(
                $id,
                $this->media,
                $this->storage,
                $this->audit
            ),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new DeleteMediaOperation(
                $id,
                $this->media,
                $this->storage,
                $this->audit
            ),
            $context
        );
    }
}

class UploadMediaOperation extends CriticalOperation
{
    private UploadedFile $file;
    private MediaRepository $media;
    private StorageService $storage;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function execute(): Media
    {
        // Validate file
        $this->validateFile();

        // Generate secure filename
        $filename = $this->generateSecureFilename();

        // Store file
        $path = $this->storage->store($this->file, $filename);

        // Create media record
        $media = $this->media->create([
            'filename' => $filename,
            'path' => $path,
            'mime_type' => $this->file->getMimeType(),
            'size' => $this->file->getSize(),
            'hash' => $this->calculateHash()
        ]);

        // Log operation
        $this->audit->logMediaUpload($media);

        return $media;
    }

    private function validateFile(): void
    {
        if (!$this->validator->validateFile($this->file)) {
            throw new ValidationException('Invalid file');
        }

        if (!$this->validator->validateMimeType($this->file->getMimeType())) {
            throw new SecurityException('Invalid file type');
        }

        if ($this->file->getSize() > config('media.max_size')) {
            throw new ValidationException('File too large');
        }
    }

    private function generateSecureFilename(): string
    {
        return Str::random(40) . '.' . $this->file->getClientOriginalExtension();
    }

    private function calculateHash(): string
    {
        return hash_file('sha256', $this->file->getPathname());
    }

    public function getRequiredPermissions(): array
    {
        return ['media.upload'];
    }
}

class DownloadMediaOperation extends CriticalOperation
{
    private int $id;
    private MediaRepository $media;
    private StorageService $storage;
    private AuditLogger $audit;

    public function execute(): MediaDownload
    {
        // Load media record
        $media = $this->media->find($this->id);
        if (!$media) {
            throw new MediaNotFoundException("Media not found: {$this->id}");
        }

        // Verify file exists
        if (!$this->storage->exists($media->path)) {
            throw new StorageException('Media file not found');
        }

        // Verify hash
        if (!$this->verifyHash($media)) {
            throw new SecurityException('Media file corrupted');
        }

        // Log access
        $this->audit->logMediaDownload($media);

        return new MediaDownload(
            $this->storage->get($media->path),
            $media->mime_type,
            $media->filename
        );
    }

    private function verifyHash(Media $media): bool
    {
        $currentHash = hash_file('sha256', $this->storage->path($media->path));
        return hash_equals($media->hash, $currentHash);
    }

    public function getRequiredPermissions(): array
    {
        return ['media.download'];
    }
}

class DeleteMediaOperation extends CriticalOperation
{
    private int $id;
    private MediaRepository $media;
    private StorageService $storage;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Load media
        $media = $this->media->find($this->id);
        if (!$media) {
            throw new MediaNotFoundException("Media not found: {$this->id}");
        }

        // Delete file
        $this->storage->delete($media->path);

        // Delete record
        $this->media->delete($media->id);

        // Log operation
        $this->audit->logMediaDelete($media);
    }

    public function getRequiredPermissions(): array
    {
        return ['media.delete'];
    }
}

class StorageService
{
    private string $root;
    private SecurityManager $security;

    public function store(UploadedFile $file, string $name): string
    {
        $path = $this->generatePath($name);
        
        $file->storeAs(
            dirname($path),
            basename($path),
            ['visibility' => 'private']
        );

        return $path;
    }

    public function exists(string $path): bool
    {
        return Storage::exists($path);
    }

    public function get(string $path): string
    {
        return Storage::get($path);
    }

    public function delete(string $path): void
    {
        Storage::delete($path);
    }

    public function path(string $path): string
    {
        return Storage::path($path);
    }

    private function generatePath(string $filename): string
    {
        $hash = substr(md5($filename), 0, 2);
        return "media/{$hash}/{$filename}";
    }
}

class MediaRepository extends BaseRepository
{
    protected function model(): string
    {
        return Media::class;
    }

    public function create(array $data): Media
    {
        return DB::transaction(function() use ($data) {
            return $this->model->create($data);
        });
    }
}
