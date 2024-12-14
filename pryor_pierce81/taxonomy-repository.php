<?php

namespace App\Core\Repository;

use App\Models\Taxonomy;
use App\Core\Events\TaxonomyEvents;
use App\Core\Exceptions\TaxonomyRepositoryException;

class TaxonomyRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Taxonomy::class;
    }

    /**
     * Create taxonomy
     */
    public function createTaxonomy(array $data): Taxonomy
    {
        try {
            DB::beginTransaction();

            $taxonomy = $this->create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'hierarchical' => $data['hierarchical'] ?? false,
                'settings' => $data['settings'] ?? [],
                'status' => 'active',
                'created_by' => auth()->id()
            ]);

            DB::commit();
            event(new TaxonomyEvents\TaxonomyCreated($taxonomy));

            return $taxonomy;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TaxonomyRepositoryException(
                "Failed to create taxonomy: {$e->getMessage()}"
            );
        }
    }

    /**
     * Create term in taxonomy
     */
    public function createTerm(int $taxonomyId, array $data): Term
    {
        try {
            DB::beginTransaction();

            $taxonomy = $this->find($taxonomyId);
            if (!$taxonomy) {
                throw new TaxonomyRepositoryException("Taxonomy not found with ID: {$taxonomyId}");
            }

            $term = $taxonomy->terms()->create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'order' => $data['order'] ?? 0,
                'metadata' => $data['metadata'] ?? []
            ]);

            DB::commit();
            $this->clearCache();
            event(new TaxonomyEvents\TermCreated($term));

            return $term;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TaxonomyRepositoryException(
                "Failed to create term: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get taxonomy hierarchy
     */
    public function getTaxonomyHierarchy(int $taxonomyId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("hierarchy.{$taxonomyId}"),
            $this->cacheTime,
            fn() => $this->model->findOrFail($taxonomyId)
                ->terms()
                ->whereNull('parent_id')
                ->with('children')
                ->orderBy('order')
                ->get()
        );
    }

    /**
     * Associate terms with content
     */
    public function associateTerms(string $contentType, int $contentId, array $termIds): void
    {
        try {
            DB::beginTransaction();

            // Remove existing associations
            DB::table('content_terms')
                ->where('content_type', $contentType)
                ->where('content_id', $contentId)
                ->delete();

            // Create new associations
            $data = array_map(function($termId) use ($contentType, $contentId) {
                return [
                    'content_type' => $contentType,
                    'content_id' => $contentId,
                    'term_id' => $termId,
                    'created_at' => now()
                ];
            }, $termIds);

            DB::table('content_terms')->insert($data);

            DB::commit();
            $this->clearCache();
            Cache::tags([$contentType])->flush();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TaxonomyRepositoryException(
                "Failed to associate terms: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get content by term
     */
    public function getContentByTerm(int $termId, array $options = []): Collection
    {
        $query = DB::table('content_terms')
            ->where('term_id', $termId);

        if (isset($options['content_type'])) {
            $query->where('content_type', $options['content_type']);
        }

        if (isset($options['status'])) {
            $query->whereExists(function($query) use ($options) {
                $query->select(DB::raw(1))
                    ->from('contents')
                    ->whereColumn('contents.id', 'content_terms.content_id')
                    ->where('contents.status', $options['status']);
            });
        }

        return $query->get();
    }

    /**
     * Reorder terms
     */
    public function reorderTerms(array $termOrders): void
    {
        try {
            DB::beginTransaction();

            foreach ($termOrders as $termId => $order) {
                DB::table('terms')
                    ->where('id', $termId)
                    ->update(['order' => $order]);
            }

            DB::commit();
            $this->clearCache();
            event(new TaxonomyEvents\TermsReordered($termOrders));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TaxonomyRepositoryException(
                "Failed to reorder terms: {$e->getMessage()}"
            );
        }
    }

    /**
     * Merge terms
     */
    public function mergeTerms(int $sourceTermId, int $targetTermId): void
    {
        try {
            DB::beginTransaction();

            // Update content associations
            DB::table('content_terms')
                ->where('term_id', $sourceTermId)
                ->update(['term_id' => $targetTermId]);

            // Update child terms
            DB::table('terms')
                ->where('parent_id', $sourceTermId)
                ->update(['parent_id' => $targetTermId]);

            // Delete source term
            DB::table('terms')->where('id', $sourceTermId)->delete();

            DB::commit();
            $this->clearCache();
            event(new TaxonomyEvents\TermsMerged($sourceTermId, $targetTermId));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TaxonomyRepositoryException(
                "Failed to merge terms: {$e->getMessage()}"
            );
        }
    }
}
