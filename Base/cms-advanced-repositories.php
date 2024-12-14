<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Core\Database\Performance\DatabasePerformanceManager;

class CommentRepository extends BaseRepository
{
    protected $cacheTTL = 900; // 15 minutes
    
    protected function model(): string
    {
        return Comment::class;
    }

    public function findByContent($contentId)
    {
        return $this->model
            ->where('content_id', $contentId)
            ->where('status', 'approved')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getRecentWithContent($limit = 10)
    {
        return $this->model
            ->with('content')
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getPendingCount()
    {
        return $this->model
            ->where('status', 'pending')
            ->count();
    }

    public function approve($id)
    {
        return $this->update(['status' => 'approved'], $id);
    }

    public function reject($id)
    {
        return $this->update(['status' => 'rejected'], $id);
    }
}

namespace App\Repositories;

use App\Models\Menu;

class MenuRepository extends BaseRepository
{
    protected $cacheTTL = 3600;
    
    protected function model(): string
    {
        return Menu::class;
    }

    public function getStructure($menuId)
    {
        return $this->model
            ->with(['items' => function($query) {
                $query->orderBy('position');
            }])
            ->findOrFail($menuId);
    }

    public function updateStructure($menuId, array $items)
    {
        $menu = $this->find($menuId);
        $menu->items()->delete();
        
        foreach ($items as $position => $item) {
            $menu->items()->create([
                'title' => $item['title'],
                'url' => $item['url'],
                'position' => $position,
                'parent_id' => $item['parent_id'] ?? null
            ]);
        }
        
        $this->clearCache();
        return $menu->fresh(['items']);
    }
}

namespace App\Repositories;

use App\Models\Setting;

class SettingRepository extends BaseRepository
{
    protected $cacheTTL = 7200;
    
    protected function model(): string
    {
        return Setting::class;
    }

    public function get($key, $default = null)
    {
        $setting = $this->findBy('key', $key);
        return $setting ? $setting->value : $default;
    }

    public function set($key, $value)
    {
        $setting = $this->findBy('key', $key);
        
        if ($setting) {
            return $this->update(['value' => $value], $setting->id);
        }
        
        return $this->create([
            'key' => $key,
            'value' => $value
        ]);
    }

    public function getAll()
    {
        return $this->all()->pluck('value', 'key');
    }
}

namespace App\Repositories;

use App\Models\Revision;

class RevisionRepository extends BaseRepository
{
    protected function model(): string
    {
        return Revision::class;
    }

    public function createRevision($model, $userId)
    {
        return $this->create([
            'revisionable_type' => get_class($model),
            'revisionable_id' => $model->id,
            'user_id' => $userId,
            'data' => json_encode($model->getAttributes()),
            'version' => $this->getNextVersion($model)
        ]);
    }

    public function getHistory($model)
    {
        return $this->model
            ->where('revisionable_type', get_class($model))
            ->where('revisionable_id', $model->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    protected function getNextVersion($model)
    {
        return $this->model
            ->where('revisionable_type', get_class($model))
            ->where('revisionable_id', $model->id)
            ->max('version') + 1;
    }

    public function restore($revisionId)
    {
        $revision = $this->find($revisionId);
        if (!$revision) return false;

        $model = $revision->revisionable_type::find($revision->revisionable_id);
        if (!$model) return false;

        $model->fill(json_decode($revision->data, true));
        return $model->save();
    }
}

namespace App\Repositories;

use App\Models\Widget;

class WidgetRepository extends BaseRepository
{
    protected $cacheTTL = 3600;
    
    protected function model(): string
    {
        return Widget::class;
    }

    public function getActive()
    {
        return $this->model
            ->where('active', true)
            ->orderBy('position')
            ->get();
    }

    public function getByArea($area)
    {
        return $this->model
            ->where('area', $area)
            ->where('active', true)
            ->orderBy('position')
            ->get();
    }

    public function updatePositions(array $positions)
    {
        foreach ($positions as $id => $position) {
            $this->update(['position' => $position], $id);
        }
        
        $this->clearCache();
        return true;
    }
}
