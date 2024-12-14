<?php

namespace App\Repositories;

use App\Models\ContactForm;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class ContactFormRepository extends BaseRepository
{
    public function __construct(ContactForm $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findUnread(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->whereNull('read_at')
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function markAsRead(int $id): bool
    {
        $result = $this->update($id, [
            'read_at' => now(),
            'read_by' => auth()->id()
        ]);
        
        $this->clearCache();
        return $result;
    }

    public function archiveOld(int $days = 30): int
    {
        $count = $this->model->where('created_at', '<', now()->subDays($days))
                            ->whereNotNull('read_at')
                            ->update(['archived' => true]);
                            
        $this->clearCache();
        return $count;
    }

    public function findByStatus(string $status): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$status], function () use ($status) {
            return $this->model->where('status', $status)
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function updateStatus(int $id, string $status, ?string $note = null): bool
    {
        $data = ['status' => $status];
        if ($note) {
            $data['admin_notes'] = $note;
        }
        
        $result = $this->update($id, $data);
        $this->clearCache();
        return $result;
    }
}
