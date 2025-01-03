<?php

namespace App\Core\Search;

class SearchService
{
    private SecurityManager $security;
    private DB $db;

    public function search(string $query, array $filters = []): array 
    {
        // Basic validation
        if (empty($query)) {
            throw new ValidationException('Query cannot be empty');
        }

        // Security check
        if (!$this->security->hasPermission('search.execute')) {
            throw new UnauthorizedException();
        }

        // Simple search
        $results = $this->db->table('content')
            ->where('content', 'LIKE', "%{$query}%")
            ->when($filters['type'] ?? false, function($q, $type) {
                return $q->where('type', $type);
            })
            ->limit(100)
            ->get();

        // Basic permission filtering
        return array_filter($results, fn($result) => 
            $this->security->hasPermission("view.{$result->type}")
        );
    }

    public function index(string $id, string $content, array $metadata = []): void
    {
        // Permission check
        if (!$this->security->hasPermission('content.index')) {
            throw new UnauthorizedException();
        }

        // Basic indexing
        $this->db->table('content')->insert([
            'id' => $id,
            'content' => $content,
            'metadata' => json_encode($metadata),
            'created_at' => now()
        ]);
    }
}

class SearchIndex 
{
    private DB $db;

    public function add(string $id, string $content): void
    {
        // Simple word extraction
        $words = array_unique(str_word_count(strtolower($content), 1));
        
        // Store words
        foreach ($words as $word) {
            $this->db->table('search_terms')->insert([
                'document_id' => $id,
                'term' => $word
            ]);
        }
    }

    public function search(array $terms): array
    {
        return $this->db->table('search_terms')
            ->whereIn('term', $terms)
            ->groupBy('document_id')
            ->having('count(*)', '>=', count($terms) * 0.5)
            ->get()
            ->pluck('document_id')
            ->toArray();
    }
}
