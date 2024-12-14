<?php

namespace App\Repositories;

use App\Models\Form;
use App\Repositories\Contracts\FormBuilderRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FormBuilderRepository extends BaseRepository implements FormBuilderRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'code'];
    protected array $filterableFields = ['status', 'type'];

    public function findByCode(string $code): ?Form
    {
        return Cache::tags(['forms'])->remember("form.{$code}", 3600, function() use ($code) {
            return $this->model
                ->where('code', $code)
                ->where('status', 'active')
                ->first();
        });
    }

    public function createForm(array $data): Form
    {
        $form = $this->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'code' => $data['code'],
            'fields' => $data['fields'],
            'validation_rules' => $data['validation_rules'] ?? [],
            'settings' => $data['settings'] ?? [],
            'status' => 'draft',
            'created_by' => auth()->id()
        ]);

        Cache::tags(['forms'])->flush();
        return $form;
    }

    public function updateFields(int $id, array $fields): bool
    {
        try {
            $result = $this->update($id, [
                'fields' => $fields,
                'last_modified_at' => now(),
                'last_modified_by' => auth()->id()
            ]);

            Cache::tags(['forms'])->flush();
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error updating form fields: ' . $e->getMessage());
            return false;
        }
    }

    public function duplicateForm(int $id): ?Form
    {
        try {
            $form = $this->findById($id);
            $newForm = $form->replicate();
            $newForm->name = $form->name . ' (Copy)';
            $newForm->code = $form->code . '_copy_' . time();
            $newForm->status = 'draft';
            $newForm->save();
            
            Cache::tags(['forms'])->flush();
            return $newForm;
        } catch (\Exception $e) {
            \Log::error('Error duplicating form: ' . $e->getMessage());
            return null;
        }
    }

    public function getFormSubmissions(int $formId): Collection
    {
        return $this->model->find($formId)
            ->submissions()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getFormAnalytics(int $formId): array
    {
        $form = $this->findById($formId);
        
        return [
            'total_submissions' => $form->submissions()->count(),
            'submission_rate' => $this->calculateSubmissionRate($form),
            'average_completion_time' => $this->calculateAverageCompletionTime($form),
            'field_completion_rates' => $this->calculateFieldCompletionRates($form),
            'submission_trends' => $this->getSubmissionTrends($form)
        ];
    }
}
