```php
namespace App\Core\Storage;

class StorageManager implements StorageInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function store(File $file, string $filename): string
    {
        return $this->security->executeProtected(function() use ($file, $filename) {
            // Generate storage path
            $path = $this->generatePath($filename);
            
            // Store file securely
            $this->moveFile($file, $path);
            
            // Update cache
            $this->cache->put("file.$filename", [
                'path' => $path,
                'stored_at' => now()
            ]);
            
            $this->audit->logFileStorage($filename, $path);
            return $path;
        });
    }

    private function moveFile(File $file, string $path): void
    {
        if (!move_uploaded_file($file->path(), $path)) {
            throw new StorageException();
        }
        
        chmod($path, 0644);
    }

    private function generatePath(string $filename): string
    {
        $hash = md5($filename);
        return storage_path(
            'media/' . 
            substr($hash, 0, 2) . '/' . 
            substr($hash, 2, 2) . '/' . 
            $filename
        );
    }
}
```
