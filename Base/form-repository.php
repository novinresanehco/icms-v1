<?php

namespace App\Repositories;

use App\Models\Form;
use App\Repositories\Contracts\FormRepositoryInterface;
use Illuminate\Support\Collection;

class FormRepository extends BaseRepository implements FormRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['status', 'type'];

    public function submitForm(int $formId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $form = $this->find($formId);
            $submission = $form->submissions()->create([
                'data' => $data,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'metadata' => $this->getSubmissionMetadata()
            ]);

            $this->processSubmissionActions($form, $submission);
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function getSubmissions(int $formId): Collection
    {
        return $this->find($formId)->submissions()
            ->with('user')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getFields(int $formId): Collection
    {
        return Cache::remember(
            $this->getCacheKey("fields.{$formId}"),
            $this->cacheTTL,
            fn() => $this->find($formId)->fields()->orderBy('order')->get()
        );
    }

    protected function processSubmissionActions(Form $form, $submission): void
    {
        foreach ($form->actions as $action) {
            match($action['type']) {
                'email' => $this->sendEmail($action, $submission),
                'webhook' => $this->triggerWebhook($action, $submission),
                'notification' => $this->createNotification($action, $submission)
            };
        }
    }
}
