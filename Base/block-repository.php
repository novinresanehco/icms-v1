<?php

namespace App\Repositories;

use App\Models\Block;
use App\Models\BlockVersion;
use App\Repositories\Contracts\BlockRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BlockRepository extends BaseRepository implements BlockRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Block();
    }

    public function findByIdentifier(string $identifier): ?Block
    {
        return $this->model->where('identifier', $identifier)->first();
    }

    public function getActiveBlocks(): Collection
    {
        return $this->model->where('status', 'active')
            ->orderBy('region')
            ->orderBy('order')
            ->get();
    }

    public function createWithContent(array $data, array $content): Block
    {
        \DB::beginTransaction();
        
        try {
            $data['order'] = $this->getNextOrder($data['region']);
            
            $block = $this->model->create($data);
            $block->content()->create($content);
            
            $this->createVersion($block);
            
            \DB::commit();
            return $block->load('content');
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    public function updateWithContent(int $id, array $data, array $content): bool
    {
        \DB::beginTransaction();
        
        try {
            $block = $this->model->findOrFail($id);
            
            if (isset($data['region']) && $data['region'] !== $block->region) {
                $data['order'] = $this->getNextOrder($data['region']);
                $this->reorderBlocksAfterMove($block->region);
            }
            
            $block->update($data);
            $block->content()->update($content);
            
            $this->createVersion($block);
            
            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            return false;
        }
    }

    public function getBlocksByRegion(string $region): Collection
    {
        return $this->model->where('region', $region)
            ->where('status', 'active')
            ->orderBy('order')
            ->get();
    }

    public function getBlocksByType(string $type): Collection
    {
        return $this->model->where('type', $type)
            ->orderBy('region')
            ->orderBy('order')
            ->get();
    }

    public function activateBlock(int $id): bool
    {
        return $this->model->findOrFail($id)->update(['status' => 'active']);
    }

    public function deactivateBlock(int $id): bool
    {
        return $this->model->findOrFail($id)->update(['status' => 'inactive']);
    }

    public function reorderBlocks(string $region, array $order): bool
    {
        \DB::beginTransaction();
        
        try {
            foreach ($order as $position => $blockId) {
                $this->model->where('id', $blockId)
                    ->where('region', $region)
                    ->update(['order' => $position]);
            }
            
            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            return false;
        }
    }

    public function duplicateBlock(int $id, string $newIdentifier): Block
    {
        \DB::beginTransaction();
        
        try {
            $original = $this->model->with('content')->findOrFail($id);
            
            $newData = $original->toArray();
            $newData['identifier'] = $newIdentifier;
            $newData['status'] = 'inactive';
            $newData['order'] = $this->getNextOrder($original->region);
            
            unset($newData['id'], $newData['created_at'], $newData['updated_at']);
            
            $content = $original->content->toArray();
            unset($content['id'], $content['block_id'], $content['created_at'], $content['updated_at']);
            
            $block = $this->createWithContent($newData, $content);
            
            \DB::commit();
            return $block;
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    public function getBlockVersions(int $id): Collection
    {
        return BlockVersion::where('block_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    protected function getNextOrder(string $region): int
    {
        return $this->model->where('region', $region)->max('order') + 1;
    }

    protected function reorderBlocksAfterMove(string $region): void
    {
        $blocks = $this->model->where('region', $region)
            ->orderBy('order')
            ->get();

        $order = 0;
        foreach ($blocks as $block) {
            $block->update(['order' => $order++]);
        }
    }

    protected function createVersion(Block $block): void
    {
        BlockVersion::create([
            'block_id' => $block->id,
            'content' => json_encode([
                'block' => $block->toArray(),
                'content' => $block->content->toArray()
            ]),
            'created_by' => auth()->id()
        ]);
    }
}
