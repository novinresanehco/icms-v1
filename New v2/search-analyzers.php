<?php
namespace App\Core\Search\Analyzers;

class TextAnalyzer implements SearchAnalyzer
{
    private array $stopWords;
    private Stemmer $stemmer;
    
    public function analyze(string $text): array
    {
        // Lowercase
        $text = strtolower($text);
        
        // Remove special chars
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        
        // Split into words
        $words = str_word_count($text, 1);
        
        // Remove stop words
        $words = array_diff($words, $this->stopWords);
        
        // Apply stemming
        $words = array_map(
            fn($word) => $this->stemmer->stem($word),
            $words
        );
        
        return array_unique($words);
    }
}

class NGramAnalyzer implements SearchAnalyzer
{
    private int $minGram;
    private int $maxGram;
    
    public function analyze(string $text): array
    {
        $grams = [];
        $length = mb_strlen($text);
        
        for ($i = 0; $i < $length; $i++) {
            for ($size = $this->minGram; $size <= $this->maxGram; $size++) {
                if ($i + $size <= $length) {
                    $grams[] = mb_substr($text, $i, $size);
                }
            }
        }
        
        return array_unique($grams);
    }
}

class PhoneticAnalyzer implements SearchAnalyzer
{
    private Metaphone $metaphone;
    
    public function analyze(string $text): array
    {
        $words = str_word_count($text, 1);
        
        return array_unique(
            array_map(
                fn($word) => $this->metaphone->encode($word),
                $words
            )
        );
    }
}

class CompoundAnalyzer implements SearchAnalyzer
{
    private array $analyzers;
    
    public function analyze(string $text): array
    {
        $results = [];
        
        foreach ($this->analyzers as $analyzer) {
            $results = array_merge(
                $results,
                $analyzer->analyze($text)
            );
        }
        
        return array_unique($results);
    }
}

interface Stemmer
{
    public function stem(string $word): string;
}

class PorterStemmer implements Stemmer
{
    public function stem(string $word): string
    {
        // Porter stemming algorithm implementation
        return $word;
    }
}

class SnowballStemmer implements Stemmer
{
    public function stem(string $word): string
    {
        // Snowball stemming algorithm implementation
        return $word;
    }
}

interface SearchFilter
{
    public function apply(array $results, array $filters): array;
}

class TypeFilter implements SearchFilter
{
    public function apply(array $results, array $filters): array
    {
        if (!isset($filters['type'])) {
            return $results;
        }
        
        return array_filter(
            $results,
            fn($result) => $result['type'] === $filters['type']
        );
    }
}

class DateFilter implements SearchFilter
{
    public function apply(array $results, array $filters): array
    {
        if (!isset($filters['date'])) {
            return $results;
        }
        
        return array_filter(
            $results,
            fn($result) => $this->dateMatches(
                $result['date'],
                $filters['date']
            )
        );
    }
    
    private function dateMatches(string $date, array $filter): bool
    {
        $timestamp = strtotime($date);
        
        if (isset($filter['from'])) {
            if ($timestamp < strtotime($filter['from'])) {
                return false;
            }
        }
        
        if (isset($filter['to'])) {
            if ($timestamp > strtotime($filter['to'])) {
                return false;
            }
        }
        
        return true;
    }
}

class PermissionFilter implements SearchFilter
{
    private SecurityManager $security;
    
    public function apply(array $results, array $filters): array
    {
        return array_filter(
            $results,
            fn($result) => $this->security->hasPermission(
                $filters['user'],
                "search.view.{$result['type']}"
            )
        );
    }
}
