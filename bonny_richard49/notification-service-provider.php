<?php

namespace App\Core\Notification\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Notification\Services\{
    NotificationService,
    NotificationTemplateService,
    NotificationPreferenceService
};
use App\Core\Notification\Repositories\{
    NotificationRepository,
    NotificationTemplateRepository,
    NotificationPreferenceRepository
};
use App\Core\Notification\Channels\{
    ChannelManager,
    EmailChannel,
    SlackChannel,
    DatabaseChannel,
    SmsChannel
};

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Register Repositories
        $this->app->singleton(NotificationRepository::class);
        $this->app->singleton(NotificationTemplateRepository::class);
        $this->app->singleton(NotificationPreferenceRepository::class);

        // Register Services
        $this->app->singleton('notification', function ($app) {
            return new NotificationService(
                $app->make(NotificationRepository::class),
                $app->make(ChannelManager::class),
                $app->make('cache'),
                $app->make('queue')
            );
        });

        $this->app->singleton('notification.template', function ($app) {
            return new NotificationTemplateService(
                $app->make(NotificationTemplateRepository::class),
                $app->make('cache')
            );
        });

        $this->app->singleton('notification.preference', function ($app) {
            return new NotificationPreferenceService(
                $app->make(NotificationPreferenceRepository::class),
                $app->make('cache')
            );
        });

        // Register Channel Manager
        $this->app->singleton(ChannelManager::class, function ($app) {
            $manager = new ChannelManager($app->make('cache'));

            // Register default channels
            $manager->addChannel('mail', new EmailChannel(
                $app->make('mail'),
                $app->make('notification.template')
            ));

            $manager->addChannel('slack', new SlackChannel(
                config('notifications.channels.slack.webhook_url'),
                $app->make('notification.template')
            ));

            $manager->addChannel('database', new DatabaseChannel(
                $app->make(NotificationRepository::class)
            ));

            if (config('notifications.channels.sms.enabled')) {
                $manager->addChannel('sms', new SmsChannel(
                    $app->make(config('notifications.channels.sms.provider')),
                    $app->make('notification.template')
                ));
            }

            return $manager;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/notifications.php' => config_path('notifications.php'),
        ], 'notification-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'notification-migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Core\Notification\Console\Commands\CleanupNotificationsCommand::class,
                \App\Core\Notification\Console\Commands\SendScheduledNotificationsCommand::class,
            ]);
        }

        // Register event subscribers
        $this->app['events']->subscribe(
            $this->app->make(\App\Core\Notification\Listeners\NotificationEventSubscriber::class)
        );

        // Schedule commands
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            
            if (config('notifications.channels.database.cleanup.enabled')) {
                $schedule->command('notifications:cleanup')
                    ->cron(config('notifications.channels.database.cleanup.schedule'));
            }
            
            $schedule->command('notifications:send-scheduled')->everyMinute();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            'notification',
            'notification.template',
            'notification.preference',
            ChannelManager::class,
            NotificationRepository::class,
            NotificationTemplateRepository::class,
            NotificationPreferenceRepository::class,
        ];
    }
}