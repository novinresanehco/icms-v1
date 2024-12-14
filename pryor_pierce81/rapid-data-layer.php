<?php

namespace App\Core\Data;

class FileSystemManager implements StorageInterface 
{
    private string $basePath;
    private SecurityManager $security;

    public function __construct(SecurityManager $security) 
    {
        $this->basePath = storage_path('cms');
        $this->security = $security;
    }

    public function store(array $data, string $type): string 
    {
        $id = $this->generateId();
        $path = $this->getPath($id, $type);
        
        $encryptedData = $this->security->encrypt(json_encode($data));
        
        file_put_contents($path, $encryptedData);
        
        return $id;
    }

    public function read(string $id, string $type): array
    {
        $path = $this->getPath($id, $type);
        
        if (!file_exists($path)) {
            throw new FileNotFoundException();
        }

        $encrypted = file_get_contents($path);
        $data = $this->security->decrypt($encrypted);
        
        return json_decode($data, true);
    }

    private function getPath(string $id, string $type): string
    {
        return $this->basePath . "/{$type}/{$id}.dat";
    }

    private function generateId(): string
    {
        return uniqid('cms_', true);
    }
}
