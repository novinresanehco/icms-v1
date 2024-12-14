<?php

namespace App\Core\Repository;

use App\Models\Contact;
use App\Core\Events\ContactEvents;
use App\Core\Exceptions\ContactRepositoryException;

class ContactRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Contact::class;
    }

    /**
     * Create contact
     */
    public function createContact(array $data): Contact
    {
        try {
            DB::beginTransaction();

            // Check for existing contact
            if (isset($data['email']) && $this->emailExists($data['email'])) {
                throw new ContactRepositoryException("Contact with this email already exists");
            }

            $contact = $this->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'company' => $data['company'] ?? null,
                'position' => $data['position'] ?? null,
                'address' => $data['address'] ?? null,
                'type' => $data['type'] ?? 'general',
                'status' => 'active',
                'metadata' => $data['metadata'] ?? [],
                'created_by' => auth()->id()
            ]);

            // Add tags if provided
            if (!empty($data['tags'])) {
                $this->addTags($contact->id, $data['tags']);
            }

            DB::commit();
            event(new ContactEvents\ContactCreated($contact));

            return $contact;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContactRepositoryException(
                "Failed to create contact: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update contact status
     */
    public function updateStatus(int $contactId, string $status): Contact
    {
        try {
            $contact = $this->find($contactId);
            if (!$contact) {
                throw new ContactRepositoryException("Contact not found with ID: {$contactId}");
            }

            $contact->update([
                'status' => $status,
                'status_changed_at' => now(),
                'status_changed_by' => auth()->id()
            ]);

            $this->clearCache();
            event(new ContactEvents\ContactStatusUpdated($contact));

            return $contact;

        } catch (\Exception $e) {
            throw new ContactRepositoryException(
                "Failed to update contact status: {$e->getMessage()}"
            );
        }
    }

    /**
     * Add interaction
     */
    public function addInteraction(int $contactId, string $type, array $data): void
    {
        try {
            DB::table('contact_interactions')->insert([
                'contact_id' => $contactId,
                'type' => $type,
                'data' => json_encode($data),
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);

            $this->clearCache();
            event(new ContactEvents\ContactInteractionAdded($contactId, $type, $data));

        } catch (\Exception $e) {
            throw new ContactRepositoryException(
                "Failed to add interaction: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get contact interactions
     */
    public function getInteractions(int $contactId, array $options = []): Collection
    {
        $query = DB::table('contact_interactions')
            ->where('contact_id', $contactId);

        if (isset($options['type'])) {
            $query->where('type', $options['type']);
        }

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        if (isset($options['to'])) {
            $query->where('created_at', '<=', $options['to']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Add tags to contact
     */
    protected function addTags(int $contactId, array $tags): void
    {
        $tagIds = [];
        foreach ($tags as $tag) {
            $tagModel = DB::table('contact_tags')
                ->firstOrCreate(
                    ['name' => $tag],
                    ['created_by' => auth()->id()]
                );
            $tagIds[] = $tagModel->id;
        }

        DB::table('contact_tag_assignments')->insert(
            collect($tagIds)->map(function($tagId) use ($contactId) {
                return [
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                    'created_at' => now()
                ];
            })->toArray()
        );
    }

    /**
     * Search contacts
     */
    public function searchContacts(array $criteria): Collection
    {
        $query = $this->model->newQuery();

        if (isset($criteria['search'])) {
            $query->where(function($q) use ($criteria) {
                $q->where('first_name', 'like', "%{$criteria['search']}%")
                  ->orWhere('last_name', 'like', "%{$criteria['search']}%")
                  ->orWhere('email', 'like', "%{$criteria['search']}%")
                  ->orWhere('company', 'like', "%{$criteria['search']}%");
            });
        }

        if (isset($criteria['type'])) {
            $query->where('type', $criteria['type']);
        }

        if (isset($criteria['status'])) {
            $query->where('status', $criteria['status']);
        }

        if (isset($criteria['tags'])) {
            $query->whereHas('tags', function($q) use ($criteria) {
                $q->whereIn('name', $criteria['tags']);
            });
        }

        return $query->get();
    }

    /**
     * Get contact statistics
     */
    public function getStatistics(): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('stats'),
            300, // 5 minutes cache
            fn() => [
                'total_contacts' => $this->model->count(),
                'active_contacts' => $this->model->where('status', 'active')->count(),
                'contacts_by_type' => $this->model->select('type', DB::raw('count(*) as count'))
                    ->groupBy('type')
                    ->pluck('count', 'type')
                    ->toArray(),
                'recent_interactions' => DB::table('contact_interactions')
                    ->select('type', DB::raw('count(*) as count'))
                    ->where('created_at', '>=', now()->subDays(30))
                    ->groupBy('type')
                    ->pluck('count', 'type')
                    ->toArray()
            ]
        );
    }

    /**
     * Export contacts
     */
    public function exportContacts(array $filters = []): string
    {
        try {
            $contacts = $this->searchContacts($filters);
            $exportPath = storage_path('app/exports/contacts_' . now()->format('Y_m_d_His') . '.csv');

            $csvData = [];
            foreach ($contacts as $contact) {
                $csvData[] = [
                    'ID' => $contact->id,
                    'First Name' => $contact->first_name,
                    'Last Name' => $contact->last_name,
                    'Email' => $contact->email,
                    'Phone' => $contact->phone,
                    'Company' => $contact->company,
                    'Type' => $contact->type,
                    'Status' => $contact->status,
                    'Created At' => $contact->created_at->format('Y-m-d H:i:s')
                ];
            }

            $this->generateCsvFile($exportPath, $csvData);
            return $exportPath;

        } catch (\Exception $e) {
            throw new ContactRepositoryException(
                "Failed to export contacts: {$e->getMessage()}"
            );
        }
    }

    /**
     * Check if email exists
     */
    protected function emailExists(string $email): bool
    {
        return $this->model->where('email', $email)->exists();
    }

    /**
     * Generate CSV file
     */
    protected function generateCsvFile(string $path, array $data): void
    {
        $handle = fopen($path, 'w');
        if (count($data) > 0) {
            fputcsv($handle, array_keys($data[0]));
        }
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }
}
