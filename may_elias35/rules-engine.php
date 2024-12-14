<?php

namespace App\Core\Audit;

class AuditRulesEngine
{
    private RuleRepository $repository;
    private RuleEvaluator $evaluator;
    private RuleCompiler $compiler;
    private ConditionMatcher $matcher;
    private ActionExecutor $executor;
    private LoggerInterface $logger;

    public function __construct(
        RuleRepository $repository,
        RuleEvaluator $evaluator,
        RuleCompiler $compiler,
        ConditionMatcher $matcher,
        ActionExecutor $executor,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->evaluator = $evaluator;
        $this->compiler = $compiler;
        $this->matcher = $matcher;
        $this->executor = $executor;
        $this->logger = $logger;
    }

    public function processEvent(AuditEvent $event): RuleProcessingResult
    {
        $startTime = microtime(true);

        try {
            // Get applicable rules
            $rules = $this->getApplicableRules($event);

            // Sort rules by priority
            $rules = $this->sortRulesByPriority($rules);

            // Evaluate rules
            $results = $this->evaluateRules($rules, $event);

            // Execute actions for matched rules
            $actionResults = $this->executeActions($results, $event);

            // Build result
            $result = new RuleProcessingResult(
                $event,
                $results,
                $actionResults,
                microtime(true) - $startTime
            );

            // Log processing
            $this->logProcessing($result);

            return $result;

        } catch (\Exception $e) {
            $this->handleProcessingError($e, $event);
            throw $e;
        }
    }

    public function processBatch(array $events): BatchProcessingResult
    {
        $results = [];
        $errors = [];

        foreach ($events as $event) {
            try {
                $results[] = $this->processEvent($event);
            } catch (\Exception $e) {
                $errors[] = [
                    'event' => $event,
                    'error' => $e->getMessage()
                ];
            }
        }

        return new BatchProcessingResult($results, $errors);
    }

    public function addRule(Rule $rule): void
    {
        try {
            // Validate rule
            $this->validateRule($rule);

            // Compile rule conditions
            $compiledConditions = $this->compiler->compile($rule->getConditions());

            // Store rule
            $this->repository->store($rule->withCompiledConditions($compiledConditions));

        } catch (\Exception $e) {
            throw new RuleAdditionException(
                "Failed to add rule: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    protected function getApplicableRules(AuditEvent $event): array
    {
        return $this->repository->findApplicable([
            'event_type' => $event->getType(),
            'category' => $event->getCategory(),
            'severity' => $event->getSeverity()
        ]);
    }

    protected function sortRulesByPriority(array $rules): array
    {
        usort($rules, function (Rule $a, Rule $b) {
            return $b->getPriority() <=> $a->getPriority();
        });

        return $rules;
    }

    protected function evaluateRules(array $rules, AuditEvent $event): array
    {
        $results = [];

        foreach ($rules as $rule) {
            $evaluation = $this->evaluateRule($rule, $event);
            if ($evaluation->matches()) {
                $results[] = $evaluation;

                if ($rule->shouldStopProcessing()) {
                    break;
                }
            }
        }

        return $results;
    }

    protected function evaluateRule(Rule $rule, AuditEvent $event): RuleEvaluation
    {
        $startTime = microtime(true);

        try {
            // Check conditions
            $matches = $this->matcher->matches(
                $rule->getCompiledConditions(),
                $event
            );

            return new RuleEvaluation(
                $rule,
                $matches,
                microtime(true) - $startTime
            );

        } catch (\Exception $e) {
            $this->logger->error('Rule evaluation failed', [
                'rule_id' => $rule->getId(),
                'event_id' => $event->getId(),
                'error' => $e->getMessage()
            ]);

            throw new RuleEvaluationException(
                "Failed to evaluate rule {$rule->getId()}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    protected function executeActions(array $matchedRules, AuditEvent $event): array
    {
        $results = [];

        foreach ($matchedRules as $evaluation) {
            $rule = $evaluation->getRule();
            
            foreach ($rule->getActions() as $action) {
                try {
                    $result = $this->executor->execute($action, $event);
                    $results[] = new ActionResult($rule, $action, $result);
                } catch (\Exception $e) {
                    $this->handleActionError($e, $rule, $action, $event);
                }
            }
        }

        return $results;
    }

    protected function validateRule(Rule $rule): void
    {
        $validator = new RuleValidator();
        
        if (!$validator->validate($rule)) {
            throw new InvalidRuleException(
                'Invalid rule: ' . implode(', ', $validator->getErrors())
            );
        }
    }

    protected function logProcessing(RuleProcessingResult $result): void
    {
        $this->logger->info('Rules processing completed', [
            'event_id' => $result->getEvent()->getId(),
            'matched_rules' => count($result->getMatchedRules()),
            'executed_actions' => count($result->getActionResults()),
            'duration' => $result->getDuration()
        ]);
    }

    protected function handleProcessingError(\Exception $e, AuditEvent $event): void
    {
        $this->logger->error('Rules processing failed', [
            'event_id' => $event->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
