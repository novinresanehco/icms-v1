<?php

namespace App\Core\Notification\Http\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Notification\Services\NotificationPreferenceService;
use App\Core\Notification\Http\Requests\{
    UpdatePreferencesRequest,
    UpdateChannelPreferenceRequest
};
use App\Core\Notification\Http\Resources\{
    NotificationPreferenceResource,
    NotificationPreferenceCollection,
    NotificationChannelResource
};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;

class NotificationPreferenceController extends Controller
{
    protected NotificationPreferenceService $preferenceService;

    /**
     * Create a new controller instance.
     *
     * @param NotificationPreferenceService $preferenceService
     */
    public function __construct(NotificationPreferenceService $preferenceService)
    {
        $this->preferenceService = $preferenceService;
    }

    /**
     * Get user's notification preferences.
     *
     * @param Request $request
     * @return NotificationPreferenceCollection
     */
    public function index(Request $request): NotificationPreferenceCollection
    {
        try {
            $preferences = $this->preferenceService->getUserPreferences(
                $request->user()
            );

            return new NotificationPreferenceCollection($preferences);

        } catch (\Exception $e) {
            Log::error('Failed to fetch notification preferences', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update user's notification preferences.
     *
     * @param UpdatePreferencesRequest $request
     * @return JsonResponse
     */
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        try {
            $preferences = $request->validated();
            
            $this->preferenceService->updatePreferences(
                $request->user(),
                $preferences
            );

            return response()->json([
                'message' => 'Notification preferences updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update notification preferences', [
                'user_id' => $request->user()->id,
                'preferences' => $preferences,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update preference for a specific channel.
     *
     * @param UpdateChannelPreferenceRequest $request
     * @param string $channel
     * @return JsonResponse
     */
    public function updateChannel(
        UpdateChannelPreferenceRequest $request,
        string $channel
    ): JsonResponse {
        try {
            $settings = $request->validated();
            
            $this->preferenceService->updateChannelPreference(
                $request->user(),
                $channel,
                $settings
            );

            return response()->json([
                'message' => "Channel {$channel} preferences updated successfully"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update channel preference', [
                'user_id' => $request->user()->id,
                'channel' => $channel,
                'settings' => $settings,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get available notification channels.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function channels(Request $request): JsonResponse
    {
        try {
            $channels = $this->preferenceService->getAvailableChannels(
                $request->user()
            );

            return response()->json([
                'channels' => $channels->map(fn($channel) => new NotificationChannelResource($channel))
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch available channels', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Reset preferences to default.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reset(Request $request): JsonResponse
    {
        try {
            $this->preferenceService->resetToDefault($request->user());

            return response()->json([
                'message' => 'Preferences reset to default successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reset preferences', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}