// app/Core/Events/Subscribers/CacheInvalidationSubscriber.php
<?php

namespace App\Core\Events\Subscribers;

use App\Core\Events\EventSubscriber;
use App\Core\Cache\CacheInvalidator;

class CacheInvalidationSubscriber extends EventSubscriber
{
    public function __construct(private CacheInvalidator $invalidator) {}

    public function getSubscribedEvents(): array
    {
        return [
            'App\Core\Widget\Events\WidgetCreated' => ['invalidateWidgetCache', 10],
            'App\Core\Widget\Events\WidgetUpdated' => ['invalidateWidgetCache', 10],
            'App\Core\Widget\Events\WidgetDeleted' => ['invalidateWidgetCache', 10],
            'App\Core\Content\Events\ContentUpdated' => ['invalidateContentCache', 10],
            'App\Core\Menu\Events\MenuUpdated' => ['invalidateMenuCache', 10]
        ];
    }

    public function invalidateWidgetCache($event): void
    {
        $this->invalidator->invalidate('widget_updated');
    }

    public function invalidateContentCache($event): void
    {
        $this->invalidator->invalidate('content_updated');
    }

    public function invalidateMenuCache($event): void
    {
        $this->invalidator->invalidate('menu_updated');
    }
}

// app/Core/Events/Subscribers/AuditLogSubscriber.php
<?php

namespace App\Core\Events\Subscribers;

use App\Core\Events\EventSubscriber;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

class AuditLogSubscriber extends EventSubscriber
{
    public function __construct(private AuditLogger $logger) {}

    public function getSubscribedEvents(): array
    {
        return [
            'App\Core\Widget\Events\*' => ['logWidgetEvent', 0],
            'App\Core\Content\Events\*' => ['logContentEvent', 0],
            'App\Core\User\Events\*' => ['logUserEvent', 0]
        ];
    }

    public function logWidgetEvent($event): void
    {
        $this->logger->log(
            'widget_event',
            [
                'event' => get_class($event),
                'widget_id' => $event->widget->id,
                'user_id' => Auth::id()
            ]
        );
    }

    public function logContentEvent($event): void
    {
        $this->logger->log(
            'content_event',
            [
                'event' => get_class($event),
                'content_id' => $event->content->id,
                'user_id' => Auth::id()
            ]
        );
    }

    public function logUserEvent($event): void
    {
        $this->logger->log(
            'user_event',
            [
                'event' => get_class($event),
                'user_id' => Auth::id()
            ]
        );
    }
}

// app/Core/Events/Subscribers/MetricsSubscriber.php
<?php

namespace App\Core\Events\Subscribers;

use App\Core\Events\EventSubscriber;
use App\Core\Metrics\MetricsCollector;

class MetricsSubscriber extends EventSubscriber
{
    public function __construct(private MetricsCollector $metrics) {}

    public function getSubscribedEvents(): array
    {
        return [
            'App\Core\Widget\Events\WidgetRendered' => ['recordWidgetMetrics', 0],
            'App\Core\Cache\Events\CacheHit' => ['recordCacheMetrics', 0],
            'App\Core\Performance\Events\SlowQuery' => ['recordPerformanceMetrics', 0]
        ];
    }

    public function recordWidgetMetrics($event): void
    {
        $this->metrics->increment('widgets.rendered');
        $this->metrics->timing('widgets.render_time', $event->renderTime);
    }

    public function recordCacheMetrics($event): void
    {
        $this->metrics->increment('cache.hits');
        $this->metrics->gauge('cache.size', $event->size);
    }

    public function recordPerformanceMetrics($event): void
    {
        $this->metrics->timing('query.execution_time', $event->executionTime);
        $this->metrics->increment('query.slow_queries');
    }
}

// app/Core/Events/Subscribers/NotificationSubscriber.php
<?php

namespace App\Core\Events\Subscribers;

use App\Core\Events\EventSubscriber;
use App\Core\Notification\NotificationSender;

class NotificationSubscriber extends EventSubscriber
{
    public function __construct(private NotificationSender $sender) {}

    public function getSubscribedEvents(): array
    {
        return [
            'App\Core\Security\Events\SecurityAlert' => ['sendSecurityNotification', 100],
            'App\Core\Performance\Events\PerformanceAlert' => ['sendPerformanceNotification', 100],
            'App\Core\System\Events\SystemAlert' => ['sendSystemNotification', 100]
        ];
    }

    public function sendSecurityNotification($event): void
    {
        $this->sender->sendUrgent('security_team', [
            'type' => 'security_alert',
            'message' => $event->getMessage(),
            'severity' => $event->getSeverity(),
            'context' => $event->getContext()
        ]);
    }

    public function sendPerformanceNotification($event): void
    {
        $this->sender->send('system_admins', [
            'type' => 'performance_alert',
            'message' => $event->getMessage(),
            'metrics' => $event->getMetrics()
        ]);
    }

    public function sendSystemNotification($event): void
    {
        $this->sender->send('system_admins', [
            'type' => 'system_alert',
            'message' => $event->getMessage(),
            'details' => $event->getDetails()
        ]);
    }
}