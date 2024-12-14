namespace App\Core\File;

class FileManager implements FileManagerInterface
{
    private SecurityManager $security;
    private StorageService $storage;
    private MetricsCollector $metrics;
    private ValidationService $validator;
    private AuditLogger $audit;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        StorageService $storage,
        MetricsCollector $metrics,
        ValidationService $validator,
        AuditLogger $audit,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->cache = $cache;
    }

    public function store(UploadedFile $file, string $path): string
    {
        return $this->security->executeCriticalOperation(
            new FileStoreOperation(
                $file,
                $path,
                $this->storage,
                $this->validator
            ),
            SecurityContext::fromRequest()
        );
    }

    public function get(string $path): File
    {
        return $this->security->executeCriticalOperation(
            new FileRetrieveOperation(
                $path,
                $this->storage,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function delete(string $path): bool
    {
        return $this->security->executeCriticalOperation(
            new FileDeleteOperation(
                $path,
                $this->storage,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function move(string $from, string $to): bool
    {
        return $this->security->executeCriticalOperation(
            new FileMoveOperation(
                $from,
                $to,
                $this->storage,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function copy(string $from, string $to): bool
    {
        return $this->security->executeCriticalOperation(
            new FileCopyOperation(
                $from,
                $to,
                $this->storage,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    private function validateFile(UploadedFile $file): void
    {
        $rules = [
            'size' => 'max:' . config('files.max_size'),
            'mimes' => config('files.allowed_mimes'),
            'name' => [
                'regex:/^[a-zA-Z0-9\-\_\.]+$/',
                'max:255'
            ]
        ];

        if (!$this->validator->validate($file, $rules)) {
            throw new FileValidationException('Invalid file');
        }

        if (!$this->validateFileContent($file)) {
            throw new FileSecurityException('File content validation failed');
        }
    }

    private function validateFileContent(UploadedFile $file): bool
    {
        $content = file_get_contents($file->path());
        
        // Check for PHP code
        if (preg_match('/<\?php/i', $content)) {
            return false;
        }

        // Check for potentially malicious JS
        if (preg_match('/<script[^>]*>/i', $content)) {
            return false;
        }

        return true;
    }

    private function validatePath(string $path): void
    {
        if (!preg_match('/^[a-zA-Z0-9\-\_\/\.]+$/', $path)) {
            throw new InvalidPathException('Invalid path format');
        }

        if (str_contains($path, '..')) {
            throw new SecurityException('Path traversal attempt detected');
        }
    }

    private function ensureDirectoryExists(string $path): void
    {
        $directory = dirname($path);
        
        if (!$this->storage->exists($directory)) {
            $this->storage->makeDirectory($directory, 0755, true);
        }
    }

    private function generateUniqueFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $counter = 1;
        $newFilename = $filename;

        while ($this->storage->exists($newFilename)) {
            $newFilename = sprintf(
                '%s_%d.%s',
                $name,
                $counter++,
                $extension
            );
        }

        return $newFilename;
    }

    private function clearFileCache(string $path): void
    {
        $this->cache->tags(['files', "file.{$path}"])->flush();
    }

    private function logFileOperation(string $operation, string $path): void
    {
        $this->audit->logFileEvent(
            FileEventType::from($operation),
            [
                'path' => $path,
                'user' => auth()->id(),
                'ip' => request()->ip()
            ]
        );
    }

    private function recordMetrics(string $operation, int $size): void
    {
        $this->metrics->increment("files.{$operation}.count");
        $this->metrics->increment("files.{$operation}.bytes", $size);
    }

    public function getUrl(string $path): string
    {
        $this->validatePath($path);
        
        return $this->cache->remember(
            "file_url.{$path}",
            3600,
            fn() => $this->storage->url($path)
        );
    }

    public function getMimeType(string $path): string
    {
        $this->validatePath($path);
        
        return $this->cache->remember(
            "file_mime.{$path}",
            3600,
            fn() => $this->storage->mimeType($path)
        );
    }

    public function getSize(string $path): int
    {
        $this->validatePath($path);
        
        return $this->cache->remember(
            "file_size.{$path}",
            3600,
            fn() => $this->storage->size($path)
        );
    }
}
