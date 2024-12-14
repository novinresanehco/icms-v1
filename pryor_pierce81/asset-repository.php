<?php

namespace App\Core\Repository;

use App\Models\Asset;
use App\Core\Events\AssetEvents;
use App\Core\Exceptions\AssetRepositoryException;
use Illuminate\Support\Facades\Storage;

class AssetRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Asset::class;
    }

    /**
     * Upload asset
     */
    public function uploadAsset(UploadedFile $file, array $metadata = []): Asset
    {
        try {
            DB::beginTransaction();

            // Store file
            $path = Storage::disk('assets')->put(
                $this->getStoragePath($file),
                $file
            );

            // Create asset record
            $asset = $this->create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'disk' => 'assets',
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => array_merge($metadata, [
                    'extension' => $file->getClientOriginalExtension(),
                    'hash' => hash_file('sha256', $file->getRealPath())
                ]),
                'created_by' => auth()->id()
            ]);

            DB::commit();
            event(new AssetEvents\AssetUploaded($asset));

            return $asset;

        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($path) && Storage::disk('assets')->exists($path)) {
                Storage::disk('assets')->delete($path);
            }
            throw new AssetRepositoryException(
                "Failed to upload asset: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get asset by type
     */
    public function getAssetsByType(string $type): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("type.{$type}"),
            $this->cacheTime,
            fn() => $this->model->where('mime_type', 'like', "{$type}/%")
                               ->latest()
                               ->get()
        );
    }

    /**
     * Update asset metadata
     */
    public function updateMetadata(int $assetId, array $metadata): Asset
    {
        try {
            $asset = $this->find($assetId);
            if (!$asset) {
                throw new AssetRepositoryException("Asset not found with ID: {$assetId}");
            }

            $asset->update([
                'metadata' => array_merge($asset->metadata, $metadata)
            ]);

            $this->clearCache();
            event(new AssetEvents\AssetMetadataUpdated($asset));

            return $asset;

        } catch (\Exception $e) {
            throw new AssetRepositoryException(
                "Failed to update asset metadata: {$e->getMessage()}"
            );
        }
    }

    /**
     * Delete asset
     */
    public function delete(int $id): bool
    {
        try {
            $asset = $this->find($id);
            if (!$asset) {
                throw new AssetRepositoryException("Asset not found with ID: {$id}");
            }

            // Delete file from storage
            Storage::disk($asset->disk)->delete($asset->path);

            // Delete record
            $result = parent::delete($id);

            event(new AssetEvents\AssetDeleted($asset));
            return $result;

        } catch (\Exception $e) {
            throw new AssetRepositoryException(
                "Failed to delete asset: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get storage path for file
     */
    protected function getStoragePath(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $hash = substr(md5(uniqid()), 0, 8);
        
        return date('Y/m/d') . "/{$hash}.{$extension}";
    }

    /**
     * Get duplicate assets
     */
    public function getDuplicates(): Collection
    {
        return $this->model
            ->select('metadata->hash', DB::raw('count(*) as count'))
            ->whereNotNull('metadata->hash')
            ->groupBy('metadata->hash')
            ->having('count', '>', 1)
            ->get();
    }

    /**
     * Get unused assets
     */
    public function getUnusedAssets(): Collection
    {
        return $this->model
            ->whereDoesntHave('usages')
            ->where('created_at', '<', now()->subDays(30))
            ->get();
    }

    /**
     * Clean unused assets
     */
    public function cleanUnusedAssets(): int
    {
        $assets = $this->getUnusedAssets();
        $count = 0;

        foreach ($assets as $asset) {
            if ($this->delete($asset->id)) {
                $count++;
            }
        }

        return $count;
    }
}
