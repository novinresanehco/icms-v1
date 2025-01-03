<?php

namespace App\Core\Search\Index;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use Illuminate\Support\Facades\DB;

class SearchIndex implements SearchIndexInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    
    public function indexDocument(string $id, array $terms, array $metadata = []): void
    {
        DB::beginTransaction();
        
        try {
            $this->deleteExistingTerms($id);
            
            foreach ($terms as $term) {
                $this->indexTerm($id, $term, $metadata);
            }
            
            $this->indexMetadata($id, $metadata);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new IndexException('Failed to index document: ' . $e->getMessage(), 0, $e);
        }
    }
    
    public function updateDocument(string $id, array $terms, array $metadata = []): void
    {
        DB::beginTransaction();
        
        try {
            $this->deleteExistingTerms($id);
            
            foreach ($terms as $term) {
                $this->indexTerm($id, $term, $metadata);
            }
            
            $this->updateMetadata($id, $metadata);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new IndexException('Failed to update document: ' . $e->getMessage(), 0, $e);
        }
    }
    
    public function deleteDocument(string $id): void
    {
        DB::beginTransaction();
        
        try {
            $this->deleteExistingTerms($id);
            $this->deleteMetadata($id);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new IndexException('Failed to delete document: ' . $e->getMessage(), 0, $e);
        }
    }
    
    public function optimize(): void
    {
        try {
            $this->removeOrphanedTerms();
            $this->removeOrphanedMetadata();
            $this->optimizeIndexTables();
            
        } catch (\Exception $e) {
            throw new IndexException('Failed to optimize index: ' . $e->getMessage(), 0, $e);
        }
    }

    private function indexTerm(string $id, string $term, array $metadata): void
    {
        DB::table('search_terms')->insert([
            'document_id' => $id,
            'term' => $term,
            'type' => $metadata['type'] ?? null,
            'created_at' => now()
        ]);
    }
    
    private function indexMetadata(string $id, array $metadata): void
    {
        DB::table('search_metadata')->insert([
            'document_id' => $id,
            'metadata' => json_encode($metadata),
            'created_at' => now()
        ]);
    }
    
    private function updateMetadata(string $id, array $metadata): void
    {
        DB::table('search_metadata')
            ->where('document_id', $id)
            ->update([
                'metadata' => json_encode($metadata),
                'updated_at' => now()
            ]);
    }
    
    private function deleteExistingTerms(string $id): void
    {
        DB::table('search_terms')
            ->where('document_id', $id)
            ->delete();
    }
    
    private function deleteMetadata(string $id): void
    {
        DB::table('search_metadata')
            ->where('document_id', $id)
            ->delete();
    }
    
    private function removeOrphanedTerms(): void
    {
        DB::table('search_terms')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('search_metadata')
                    ->whereRaw('search_metadata.document_id = search_terms.document_id');
            })
            ->delete();
    }
    
    private function removeOrphanedMetadata(): void
    {
        DB::table('search_metadata')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('search_terms')
                    ->whereRaw('search_terms.document_id = search_metadata.document_id');
            })
            ->delete();
    }
    
    private function optimizeIndexTables(): void
    {
        DB::statement('OPTIMIZE TABLE search_terms');
        DB::statement('OPTIMIZE TABLE search_metadata');
    }
}
