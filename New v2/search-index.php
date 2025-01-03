<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\IndexingException;

class SearchIndex implements SearchIndexInterface
{
    private const MIN_WORD_LENGTH = 3;
    private const MAX_WORDS_PER_DOC = 10000;

    /**
     * Add document to search index with optimization and validation
     *
     * @throws IndexingException
     */
    public function add(string $id, string $content): void
    {
        $words = $this->extractWords($content);
        
        if (count($words) > self::MAX_WORDS_PER_DOC) {
            throw new IndexingException('Document exceeds maximum word limit');
        }

        DB::transaction(function() use ($id, $words) {
            // Remove existing terms for this document
            DB::table('search_terms')->where('document_id', $id)->delete();
            
            // Insert new terms in batches
            foreach (array_chunk($words, 1000) as $batch) {
                $records = array_map(fn($word) => [
                    'document_id' => $id,
                    'term' => $word,
                    'created_at' => now()
                ], $batch);
                
                DB::table('search_terms')->insert($records);
            }
        });
    }

    /**
     * Search for documents matching terms with relevance ranking
     */
    public function search(array $terms): array
    {
        return DB::table('search_terms')
            ->select('document_id')
            ->selectRaw('COUNT(*) as matches')
            ->whereIn('term', $terms)
            ->groupBy('document_id')
            ->having('matches', '>=', intval(count($terms) * 0.5))
            ->orderByDesc('matches')
            ->limit(1000)
            ->get()
            ->pluck('document_id')
            ->toArray();
    }

    /**
     * Extract and normalize searchable words
     */
    private function extractWords(string $content): array
    {
        // Convert to lowercase and split into words
        $words = str_word_count(strtolower($content), 1);
        
        // Filter and normalize
        $words = array_filter($words, function($word) {
            $length = strlen($word);
            return $length >= self::MIN_WORD_LENGTH && $length <= 100;
        });

        // Remove duplicates and return
        return array_unique($words);
    }
}
