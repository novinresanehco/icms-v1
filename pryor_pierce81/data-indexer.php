<?php

namespace App\Core\Data;

class RapidIndexer
{
    private string $indexPath;
    
    public function __construct()
    {
        $this->indexPath = storage_path('cms/indexes');
    }

    public function index(string $id, array $data, string $type): void
    {
        $index = [
            'id' => $id,
            'title' => $data['title'] ?? '',
            'type' => $type,
            'timestamp' => time()
        ];

        $path = $this->getIndexPath($type);
        $indexes = $this->loadIndexes($path);
        $indexes[] = $index;
        
        file_put_contents($path, json_encode($indexes));
    }
    
    public function search(string $query, string $type): array
    {
        $indexes = $this->loadIndexes($this->getIndexPath($type));
        
        return array_filter($indexes, function($index) use ($query) {
            return stripos($index['title'], $query) !== false;
        });
    }

    private function loadIndexes(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true);
    }

    private function getIndexPath(string $type): string 
    {
        return $this->indexPath . "/{$type}.idx";
    }
}
