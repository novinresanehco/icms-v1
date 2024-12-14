<?php

namespace App\Core\Repository;

use App\Models\ContactCampaign;
use App\Core\Events\CampaignEvents;
use App\Core\Exceptions\CampaignRepositoryException;

class ContactCampaignRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return ContactCampaign::class;
    }

    /**
     * Create campaign
     */
    public function createCampaign(array $data): ContactCampaign
    {
        try {
            DB::beginTransaction();

            $campaign = $this->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'status' => 'draft',
                'content' => $data['content'] ?? [],
                'settings' => $data['settings'] ?? [],
                'schedule' => $data['schedule'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'created_by' => auth()->id()
            ]);

            // Associate target groups
            if (!empty($data['target_groups'])) {
                $this->assignTargetGroups($campaign->id, $data['target_groups']);
            }

            DB::commit();
            event(new CampaignEvents\CampaignCreated($campaign));

            return $campaign;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new CampaignRepositoryException(
                "Failed to create campaign: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update campaign status
     */
    public function updateStatus(int $campaignId, string $status): ContactCampaign
    {
        try {
            $campaign = $this->find($campaignId);
            if (!$campaign) {
                throw new CampaignRepositoryException("Campaign not found with ID: {$campaignId}");
            }

            if ($status === 'active' && !$this->canActivateCampaign($campaign)) {
                throw new CampaignRepositoryException("Campaign cannot be activated: Missing required configuration");
            }

            $campaign->update([
                'status' => $status,
                'status_changed_at' => now(),
                'status_changed_by' => auth()->id()
            ]);

            $this->clearCache();
            event(new CampaignEvents\CampaignStatusUpdated($campaign));

            return $campaign;

        } catch (\Exception $e) {
            throw new CampaignRepositoryException(
                "Failed to update campaign status: {$e->getMessage()}"
            );
        }
    }

    /**
     * Assign target groups to campaign
     */
    protected function assignTargetGroups(int $campaignId, array $groupIds): void
    {
        $data = array_map(function($groupId) use ($campaignId) {
            return [
                'campaign_id' => $campaignId,
                'group_id' => $groupId,
                'assigned_at' => now(),
                'assigned_by' => auth()->id()
            ];
        }, $groupIds);

        DB::table('campaign_target_groups')->insert($data);
    }

    /**
     * Get campaign target contacts
     */
    public function getTargetContacts(int $campaignId): Collection
    {
        return DB::table('contacts')
            ->join('contact_group_members', 'contacts.id', '=', 'contact_group_members.contact_id')
            ->join('campaign_target_groups', 'contact_group_members.group_id', '=', 'campaign_target_groups.group_id')
            ->where('campaign_target_groups.campaign_id', $campaignId)
            ->where('contacts.status', 'active')
            ->distinct()
            ->select('contacts.*')
            ->get();
    }

    /**
     * Log campaign interaction
     */
    public function logInteraction(int $campaignId, int $contactId, string $type, array $data = []): void
    {
        try {
            DB::table('campaign_interactions')->insert([
                'campaign_id' => $campaignId,
                'contact_id' => $contactId,
                'type' => $type,
                'data' => json_encode($data),
                'created_at' => now()
            ]);

            event(new CampaignEvents\CampaignInteractionLogged($campaignId, $contactId, $type));

        } catch (\Exception $e) {
            throw new CampaignRepositoryException(
                "Failed to log campaign interaction: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get campaign statistics
     */
    public function getCampaignStatistics(int $campaignId): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("stats.{$campaignId}"),
            300, // 5 minutes cache
            function() use ($campaignId) {
                $interactions = DB::table('campaign_interactions')
                    ->where('campaign_id', $campaignId)
                    ->get();

                $targetContacts = $this->getTargetContacts($campaignId);

                return [
                    'total_targets' => $targetContacts->count(),
                    'total_interactions' => $interactions->count(),
                    'interaction_types' => $interactions->groupBy('type')
                        ->map(function($group) {
                            return $group->count();
                        })->toArray(),
                    'interaction_rate' => $targetContacts->count() > 0 
                        ? ($interactions->unique('contact_id')->count() / $targetContacts->count()) * 100 
                        : 0,
                    'recent_interactions' => $interactions
                        ->where('created_at', '>=', now()->subDays(7))
                        ->count()
                ];
            }
        );
    }

    /**
     * Check if campaign can be activated
     */
    protected function canActivateCampaign(ContactCampaign $campaign): bool
    {
        // Check for required configuration
        if (empty($campaign->content)) {
            return false;
        }

        // Check for target groups
        $hasTargets = DB::table('campaign_target_groups')
            ->where('campaign_id', $campaign->id)
            ->exists();

        if (!$hasTargets) {
            return false;
        }

        // Additional validation based on campaign type
        switch ($campaign->type) {
            case 'email':
                return !empty($campaign->content['subject']) && !empty($campaign->content['body']);
            case 'sms':
                return !empty($campaign->content['message']);
            case 'notification':
                return !empty($campaign->content['title']) && !empty($campaign->content['message']);
            default:
                return true;
        }
    }

    /**
     * Get scheduled campaigns
     */
    public function getScheduledCampaigns(): Collection
    {
        return $this->model
            ->where('status', 'scheduled')
            ->where('schedule->start_date', '<=', now())
            ->where(function($query) {
                $query->whereNull('schedule->end_date')
                    ->orWhere('schedule->end_date', '>=', now());
            })
            ->get();
    }

    /**
     * Duplicate campaign
     */
    public function duplicateCampaign(int $campaignId, array $override = []): ContactCampaign
    {
        try {
            $campaign = $this->find($campaignId);
            if (!$campaign) {
                throw new CampaignRepositoryException("Campaign not found with ID: {$campaignId}");
            }

            DB::beginTransaction();

            $newCampaign = $this->create(array_merge([
                'name' => $campaign->name . ' (Copy)',
                'description' => $campaign->description,
                'type' => $campaign->type,
                'content' => $campaign->content,
                'settings' => $campaign->settings,
                'metadata' => $campaign->metadata,
                'status' => 'draft'
            ], $override));

            // Copy target groups
            $targetGroups = DB::table('campaign_target_groups')
                ->where('campaign_id', $campaignId)
                ->pluck('group_id')
                ->toArray();

            if (!empty($targetGroups)) {
                $this->assignTargetGroups($newCampaign->id, $targetGroups);
            }

            DB::commit();
            event(new CampaignEvents\CampaignDuplicated($campaign, $newCampaign));

            return $newCampaign;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new CampaignRepositoryException(
                "Failed to duplicate campaign: {$e->getMessage()}"
            );
        }
    }
}
