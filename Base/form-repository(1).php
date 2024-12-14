<?php

namespace App\Core\Repositories;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class FormRepository extends AdvancedRepository
{
    protected $model = Form::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getActiveForm(string $identifier): ?Form
    {
        return $this->executeQuery(function() use ($identifier) {
            return $this->cache->remember("form.{$identifier}", function() use ($identifier) {
                return $this->model
                    ->where('identifier', $identifier)
                    ->where('active', true)
                    ->with(['fields', 'validations'])
                    ->first();
            });
        });
    }

    public function createSubmission(Form $form, array $data): FormSubmission
    {
        return $this->executeTransaction(function() use ($form, $data) {
            return $form->submissions()->create([
                'data' => $data,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);
        });
    }

    public function getSubmissions(Form $form, array $filters = []): Collection
    {
        return $this->executeQuery(function() use ($form, $filters) {
            $query = $form->submissions();
            
            if (isset($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }
            
            if (isset($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }
            
            return $query->orderBy('created_at', 'desc')->get();
        });
    }

    public function updateFormStructure(Form $form, array $fields): void
    {
        $this->executeTransaction(function() use ($form, $fields) {
            $form->fields()->delete();
            
            foreach ($fields as $order => $field) {
                $form->fields()->create([
                    'name' => $field['name'],
                    'type' => $field['type'],
                    'label' => $field['label'],
                    'options' => $field['options'] ?? [],
                    'validations' => $field['validations'] ?? [],
                    'order' => $order
                ]);
            }
            
            $this->cache->forget("form.{$form->identifier}");
        });
    }
}
