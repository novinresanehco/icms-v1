<?php

namespace App\Core\Notification\Analytics\Providers;

use App\Core\Notification\Analytics\Services\{
    AlertChannelManager,
    AlertPolicyService
};
use App\Core\Notification\Analytics\Channels\{
    SlackAlertChannel,
    EmailAlertChannel,
    SmsAlertChannel
};
use Illuminate\Support\ServiceProvider;

class AlertServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AlertChannelManager::class, function ($app) {
            $manager = new AlertChannelManager();

            // Register default channels
            $manager->registerChannel('slack', new SlackAlertChannel(
                config('notification.analytics.channels.slack')
            ));

            $manager->registerChannel('email', new EmailAlertChannel(
                config('notification.analytics.channels.email')
            ));

            $manager->registerChannel('sms', new SmsAlertChannel(
                config('notification.analytics.channels.sms')
            ));

            // Register fallback channels
            $manager->registerFallbackChannel('slack', new EmailAlertChannel(
                config('notification.analytics.fallbacks.slack')
            ));

            return $manager;
        });

        $this->app->singleton(AlertPolicyService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/notification.php' => config_path('notification.php'),
        ], 'notification-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/notification'),
        ], 'notification-views');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'notification');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
