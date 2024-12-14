<?php

namespace App\Core\Monitoring\Alert;

class RealTimeAlertSystem
{
    private AlertEvaluator $evaluator;
    private NotificationDispatcher $dispatcher;
    private AlertPrioritizer $prioritizer;
    private AlertAggregator $aggregator;
    private ThrottleManager $throttleManager;
    private AlertRepository $repository;

    public function __construct(
        AlertEvaluator $evaluator,
        NotificationDispatcher $dispatcher,
        AlertPrioritizer $prioritizer,
        AlertAggregator $aggregator,
        ThrottleManager $throttleManager,
        AlertRepository $repository
    ) {
        $this->evaluator = $evaluator;
        $this->dispatcher = $dispatcher;
        $this->prioritizer = $prioritizer;
        $this->aggregator = $aggregator;
        $this->throttleManager = $throttleManager;
        $this->repository = $repository;
    }

    public function processAlert(AlertEvent $event): AlertResult
    {
        try {
            // Start alert processing transaction
            $transaction = DB::beginTransaction();

            // Check throttling
            if ($this->throttleManager->shouldThrottle($event)) {
                return new ThrottledAlertResult($event);
            }

            // Evaluate alert conditions
            $evaluation = $this->evaluator->evaluate($event);

            if (!$evaluation->requiresAlert()) {
                return new SkippedAlertResult($event, $evaluation);
            }

            // Determine priority
            $priority = $this->prioritizer->getPriority($event, $evaluation);

            // Check for similar alerts
            $similarAlerts = $this->aggregator->findSimilar($event);
            
            if ($this->shouldAggregate($similarAlerts)) {
                $aggregatedAlert = $this->aggregator->aggregate($event, $similarAlerts);
                $this->repository->save($aggregatedAlert);
                return new AggregatedAlertResult($aggregatedAlert);
            }

            // Create and save alert
            $alert = $this->createAlert($event, $evaluation, $priority);
            $this->repository->save($alert);

            // Dispatch notifications
            $this->dispatchNotifications($alert);

            // Commit transaction
            $transaction->commit();

            return new SuccessfulAlertResult($alert);

        } catch (\Exception $e) {
            $transaction->rollback();
            throw new AlertProcessingException(
                "Failed to process alert: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function shouldAggregate(array $similarAlerts): bool
    {
        return !empty($similarAlerts) && 
               count($similarAlerts) >= $this->aggregator->getThreshold();
    }

    private function createAlert(
        AlertEvent $event,
        AlertEvaluation $evaluation,
        AlertPriority $priority
    ): Alert {
        return new Alert(
            $event->getId(),
            $event->getType(),
            $evaluation->getSeverity(),
            $priority,
            $event->getContext(),
            $evaluation->getMetadata()
        );
    }

    private function dispatchNotifications(Alert $alert): void
    {
        $channels = $this->determineNotificationChannels($alert);
        
        foreach ($channels as $channel) {
            try {
                $this->dispatcher->dispatch($alert, $channel);
            } catch (NotificationException $e) {
                Log::error("Failed to dispatch notification", [
                    'alert' => $alert->getId(),
                    'channel' => $channel->getName(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function determineNotificationChannels(Alert $alert): array
    {
        return $this->dispatcher->getChannels(
            $alert->getPriority(),
            $alert->getSeverity(),
            $alert->getType()
        );
    }
}

class AlertEvaluator
{
    private RuleEngine $ruleEngine;
    private ContextAnalyzer $contextAnalyzer;
    private ThresholdManager $thresholdManager;

    public function evaluate(AlertEvent $event): AlertEvaluation
    {
        // Analyze context
        $context = $this->contextAnalyzer->analyze($event->getContext());

        // Apply rules
        $ruleResults = $this->ruleEngine->applyRules($event, $context);

        // Check thresholds
        $thresholdResults = $this->thresholdManager->checkThresholds(
            $event,
            $context
        );

        return new AlertEvaluation(
            $this->determineSeverity($ruleResults, $thresholdResults),
            $this->shouldTriggerAlert($ruleResults, $thresholdResults),
            $this->generateMetadata($ruleResults, $thresholdResults, $context)
        );
    }

    private function determineSeverity(
        RuleResults $ruleResults,
        ThresholdResults $thresholdResults
    ): AlertSeverity {
        return max(
            $ruleResults->getHighestSeverity(),
            $thresholdResults->getHighestSeverity()
        );
    }

    private function shouldTriggerAlert(
        RuleResults $ruleResults,
        ThresholdResults $thresholdResults
    ): bool {
        return $ruleResults->hasMatchingRules() ||
               $thresholdResults->hasExceededThresholds();
    }
}

class NotificationDispatcher
{
    private ChannelRegistry $channelRegistry;
    private TemplateEngine $templateEngine;
    private DeliveryTracker $deliveryTracker;

    public function dispatch(Alert $alert, NotificationChannel $channel): void
    {
        // Prepare notification content
        $content = $this->templateEngine->render(
            $channel->getTemplate(),
            $this->prepareTemplateData($alert)
        );

        // Send notification
        $deliveryId = $channel->send(new Notification(
            $alert,
            $content,
            $this->getNotificationOptions($alert, $channel)
        ));

        // Track delivery
        $this->deliveryTracker->track(
            $alert,
            $channel,
            $deliveryId
        );
    }

    private function prepareTemplateData(Alert $alert): array
    {
        return [
            'id' => $alert->getId(),
            'type' => $alert->getType(),
            'severity' => $alert->getSeverity()->getName(),
            'priority' => $alert->getPriority()->getLevel(),
            'context' => $alert->getContext(),
            'timestamp' => $alert->getTimestamp(),
            'metadata' => $alert->getMetadata()
        ];
    }

    public function getChannels(
        AlertPriority $priority,
        AlertSeverity $severity,
        string $type
    ): array {
        return $this->channelRegistry->getChannels([
            'priority' => $priority,
            'severity' => $severity,
            'type' => $type
        ]);
    }
}

class AlertAggregator
{
    private SimilarityCalculator $similarityCalculator;
    private TimeWindowManager $timeWindowManager;
    private AggregationConfig $config;

    public function findSimilar(AlertEvent $event): array
    {
        $timeWindow = $this->timeWindowManager->getWindow($event->getType());
        
        $candidates = $this->repository->findInWindow(
            $event->getType(),
            $timeWindow
        );

        return array_filter(
            $candidates,
            fn($candidate) => $this->isSimilar($event, $candidate)
        );
    }

    public function aggregate(AlertEvent $event, array $similarAlerts): AggregatedAlert
    {
        return new AggregatedAlert(
            $event,
            $similarAlerts,
            $this->calculateAggregateMetrics($event, $similarAlerts),
            $this->timeWindowManager->getCurrentWindow()
        );
    }

    private function isSimilar(AlertEvent $event, Alert $candidate): bool
    {
        return $this->similarityCalculator->calculate($event, $candidate) >=
               $this->config->getSimilarityThreshold();
    }

    private function calculateAggregateMetrics(AlertEvent $event, array $similarAlerts): array
    {
        return [
            'count' => count($similarAlerts) + 1,
            'first_occurrence' => min(array_map(
                fn($alert) => $alert->getTimestamp(),
                $similarAlerts
            )),
            'last_occurrence' => $event->getTimestamp(),
            'severity_distribution' => $this->calculateSeverityDistribution($similarAlerts)
        ];
    }
}

class Alert
{
    private string $id;
    private string $type;
    private AlertSeverity $severity;
    private AlertPriority $priority;
    private array $context;
    private array $metadata;
    private float $timestamp;

    public function __construct(
        string $id,
        string $type,
        AlertSeverity $severity,
        AlertPriority $priority,
        array $context,
        array $metadata
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->severity = $severity;
        $this->priority = $priority;
        $this->context = $context;
        $this->metadata = $metadata;
        $this->timestamp = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSeverity(): AlertSeverity
    {
        return $this->severity;
    }

    public function getPriority(): AlertPriority
    {
        return $this->priority;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}
