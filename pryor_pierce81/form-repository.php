<?php

namespace App\Core\Repository;

use App\Models\Form;
use App\Core\Events\FormEvents;
use App\Core\Exceptions\FormRepositoryException;

class FormRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Form::class;
    }

    /**
     * Create form
     */
    public function createForm(array $data): Form
    {
        try {
            DB::beginTransaction();

            // Validate form structure
            $this->validateFormStructure($data['fields'] ?? []);

            $form = $this->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'fields' => $data['fields'] ?? [],
                'validation_rules' => $data['validation_rules'] ?? [],
                'settings' => $data['settings'] ?? [],
                'status' => 'active',
                'created_by' => auth()->id()
            ]);

            DB::commit();
            event(new FormEvents\FormCreated($form));

            return $form;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new FormRepositoryException(
                "Failed to create form: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update form structure
     */
    public function updateFormStructure(int $formId, array $fields): Form
    {
        try {
            $form = $this->find($formId);
            if (!$form) {
                throw new FormRepositoryException("Form not found with ID: {$formId}");
            }

            // Validate new structure
            $this->validateFormStructure($fields);

            $form->update([
                'fields' => $fields,
                'updated_at' => now()
            ]);

            $this->clearCache();
            event(new FormEvents\FormStructureUpdated($form));

            return $form;

        } catch (\Exception $e) {
            throw new FormRepositoryException(
                "Failed to update form structure: {$e->getMessage()}"
            );
        }
    }

    /**
     * Store form submission
     */
    public function storeSubmission(int $formId, array $data): void
    {
        try {
            $form = $this->find($formId);
            if (!$form) {
                throw new FormRepositoryException("Form not found with ID: {$formId}");
            }

            DB::table('form_submissions')->insert([
                'form_id' => $formId,
                'data' => json_encode($data),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);

            event(new FormEvents\FormSubmitted($form, $data));

        } catch (\Exception $e) {
            throw new FormRepositoryException(
                "Failed to store form submission: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get form submissions
     */
    public function getSubmissions(int $formId, array $options = []): Collection
    {
        $query = DB::table('form_submissions')
            ->where('form_id', $formId);

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        if (isset($options['to'])) {
            $query->where('created_at', '<=', $options['to']);
        }

        if (isset($options['search'])) {
            $query->where('data', 'like', "%{$options['search']}%");
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Export form submissions
     */
    public function exportSubmissions(int $formId, string $format = 'csv'): string
    {
        try {
            $form = $this->find($formId);
            if (!$form) {
                throw new FormRepositoryException("Form not found with ID: {$formId}");
            }

            $submissions = $this->getSubmissions($formId);
            $exportPath = storage_path("app/exports/form_{$formId}_" . time() . ".{$format}");

            $this->generateExport($submissions, $exportPath, $format);
            return $exportPath;

        } catch (\Exception $e) {
            throw new FormRepositoryException(
                "Failed to export form submissions: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get form statistics
     */
    public function getFormStatistics(int $formId): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("stats.{$formId}"),
            300, // 5 minutes cache
            function() use ($formId) {
                $total = DB::table('form_submissions')
                    ->where('form_id', $formId)
                    ->count();

                $today = DB::table('form_submissions')
                    ->where('form_id', $formId)
                    ->where('created_at', '>=', now()->startOfDay())
                    ->count();

                $lastWeek = DB::table('form_submissions')
                    ->where('form_id', $formId)
                    ->where('created_at', '>=', now()->subWeek())
                    ->count();

                return [
                    'total_submissions' => $total,
                    'submissions_today' => $today,
                    'submissions_last_week' => $lastWeek,
                    'average_per_day' => $total > 0 ? 
                        round($total / max(1, now()->diffInDays($this->getFirstSubmissionDate($formId))), 2) : 0
                ];
            }
        );
    }

    /**
     * Validate form structure
     */
    protected function validateFormStructure(array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($field['type']) || !isset($field['name'])) {
                throw new FormRepositoryException(
                    "Invalid field structure: Each field must have a type and name"
                );
            }

            if (!in_array($field['type'], $this->getAllowedFieldTypes())) {
                throw new FormRepositoryException(
                    "Invalid field type: {$field['type']}"
                );
            }
        }
    }

    /**
     * Get allowed field types
     */
    protected function getAllowedFieldTypes(): array
    {
        return [
            'text',
            'textarea',
            'email',
            'number',
            'select',
            'checkbox',
            'radio',
            'file',
            'date',
            'time',
            'datetime'
        ];
    }

    /**
     * Get first submission date
     */
    protected function getFirstSubmissionDate(int $formId): ?Carbon
    {
        $first = DB::table('form_submissions')
            ->where('form_id', $formId)
            ->orderBy('created_at')
            ->first();

        return $first ? Carbon::parse($first->created_at) : null;
    }
}
