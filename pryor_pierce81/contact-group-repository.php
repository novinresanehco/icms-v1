<?php

namespace App\Core\Repository;

use App\Models\ContactGroup;
use App\Core\Events\ContactGroupEvents;
use App\Core\Exceptions\ContactGroupRepositoryException;

class ContactGroupRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return ContactGroup::class;
    }

    /**
     * Create contact group
     */
    public function createGroup(array $data): ContactGroup
    {
        try {
            DB::beginTransaction();

            $group = $this->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'manual',
                'rules' => $data['rules'] ?? [],
                'status' => 'active',
                'metadata' => $data['metadata'] ?? [],
                'created_by' => auth()->id()
            ]);

            // Add initial members if provided
            if (!empty($data['members'])) {
                $this->addMembers($group->id, $data['members']);
            }

            DB::commit();
            event(new ContactGroupEvents\GroupCreated($group));

            return $group;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContactGroupRepositoryException(
                "Failed to create group: {$e->getMessage()}"
            );
        }
    }

    /**
     * Add members to group
     */
    public function addMembers(int $groupId, array $contactIds): void
    {
        try {
            $group = $this->find($groupId);
            if (!$group) {
                throw new ContactGroupRepositoryException("Group not found with ID: {$groupId}");
            }

            $existingMembers = DB::table('contact_group_members')
                ->where('group_id', $groupId)
                ->pluck('contact_id')
                ->toArray();

            $newMembers = array_diff($contactIds, $existingMembers);
            
            if (!empty($newMembers)) {
                DB::table('contact_group_members')
                    ->insert(array_map(function($contactId) use ($groupId) {
                        return [
                            'group_id' => $groupId,
                            'contact_id' => $contactId,
                            'added_at' => now(),
                            'added_by' => auth()->id()
                        ];
                    }, $newMembers));

                $this->clearCache();
                event(new ContactGroupEvents\MembersAdded($group, $newMembers));
            }

        } catch (\Exception $e) {
            throw new ContactGroupRepositoryException(
                "Failed to add members: {$e->getMessage()}"
            );
        }
    }

    /**
     * Remove members from group
     */
    public function removeMembers(int $groupId, array $contactIds): void
    {
        try {
            $group = $this->find($groupId);
            if (!$group) {
                throw new ContactGroupRepositoryException("Group not found with ID: {$groupId}");
            }

            DB::table('contact_group_members')
                ->where('group_id', $groupId)
                ->whereIn('contact_id', $contactIds)
                ->delete();

            $this->clearCache();
            event(new ContactGroupEvents\MembersRemoved($group, $contactIds));

        } catch (\Exception $e) {
            throw new ContactGroupRepositoryException(
                "Failed to remove members: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get group members
     */
    public function getMembers(int $groupId, array $options = []): Collection
    {
        $query = DB::table('contacts')
            ->join('contact_group_members', 'contacts.id', '=', 'contact_group_members.contact_id')
            ->where('contact_group_members.group_id', $groupId);

        if (isset($options['status'])) {
            $query->where('contacts.status', $options['status']);
        }

        if (isset($options['added_after'])) {
            $query->where('contact_group_members.added_at', '>=', $options['added_after']);
        }

        return $query->select('contacts.*', 'contact_group_members.added_at')
                    ->get();
    }

    /**
     * Apply dynamic group rules
     */
    public function applyDynamicRules(int $groupId): array
    {
        try {
            $group = $this->find($groupId);
            if (!$group || $group->type !== 'dynamic') {
                throw new ContactGroupRepositoryException("Invalid dynamic group");
            }

            $query = DB::table('contacts');
            foreach ($group->rules as $rule) {
                $this->applyRule($query, $rule);
            }

            $newMembers = $query->pluck('id')->toArray();
            $currentMembers = DB::table('contact_group_members')
                ->where('group_id', $groupId)
                ->pluck('contact_id')
                ->toArray();

            $toAdd = array_diff($newMembers, $currentMembers);
            $toRemove = array_diff($currentMembers, $newMembers);

            if (!empty($toAdd)) {
                $this->addMembers($groupId, $toAdd);
            }

            if (!empty($toRemove)) {
                $this->removeMembers($groupId, $toRemove);
            }

            return [
                'added' => $toAdd,
                'removed' => $toRemove
            ];

        } catch (\Exception $e) {
            throw new ContactGroupRepositoryException(
                "Failed to apply dynamic rules: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get group statistics
     */
    public function getGroupStatistics(int $groupId): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("stats.{$groupId}"),
            300, // 5 minutes cache
            function() use ($groupId) {
                $members = $this->getMembers($groupId);
                
                return [
                    'total_members' => $members->count(),
                    'active_members' => $members->where('status', 'active')->count(),
                    'inactive_members' => $members->where('status', 'inactive')->count(),
                    'member_types' => $members->groupBy('type')
                        ->map(function($group) {
                            return $group->count();
                        })->toArray(),
                    'added_last_30_days' => DB::table('contact_group_members')
                        ->where('group_id', $groupId)
                        ->where('added_at', '>=', now()->subDays(30))
                        ->count()
                ];
            }
        );
    }

    /**
     * Apply rule to query
     */
    protected function applyRule($query, array $rule): void
    {
        switch ($rule['operator']) {
            case 'equals':
                $query->where($rule['field'], $rule['value']);
                break;
            case 'contains':
                $query->where($rule['field'], 'like', "%{$rule['value']}%");
                break;
            case 'greater_than':
                $query->where($rule['field'], '>', $rule['value']);
                break;
            case 'less_than':
                $query->where($rule['field'], '<', $rule['value']);
                break;
            case 'in':
                $query->whereIn($rule['field'], $rule['value']);
                break;
            case 'has_tag':
                $query->whereExists(function($q) use ($rule) {
                    $q->select(DB::raw(1))
                      ->from('contact_tag_assignments')
                      ->whereColumn('contact_tag_assignments.contact_id', 'contacts.id')
                      ->whereIn('contact_tag_assignments.tag_id', $rule['value']);
                });
                break;
        }
    }
}
