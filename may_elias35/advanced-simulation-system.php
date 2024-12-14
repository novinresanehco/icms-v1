```php
namespace App\Core\Media\Analytics\Simulation;

class AdvancedSimulationEngine
{
    protected ModelManager $modelManager;
    protected SimulationRunner $runner;
    protected ResultsProcessor $processor;
    protected StateManager $stateManager;

    public function __construct(
        ModelManager $modelManager,
        SimulationRunner $runner,
        ResultsProcessor $processor,
        StateManager $stateManager
    ) {
        $this->modelManager = $modelManager;
        $this->runner = $runner;
        $this->processor = $processor;
        $this->stateManager = $stateManager;
    }

    public function runSimulation(SimulationConfig $config): SimulationResult
    {
        // Initialize simulation state
        $state = $this->stateManager->initialize($config);

        // Get appropriate models
        $models = $this->modelManager->getModels($config->getRequiredModels());

        // Run simulation
        $results = $this->runner->run($models, $state, [
            'iterations' => $config->getIterations(),
            'timeSteps' => $config->getTimeSteps(),
            'convergenceCriteria' => $config->getConvergenceCriteria()
        ]);

        // Process results
        return $this->processor->process($results, [
            'aggregation' => $config->getAggregationMethod(),
            'metrics' => $config->getRequiredMetrics()
        ]);
    }
}

class SimulationRunner
{
    protected TimeStepManager $timeStepManager;
    protected ConvergenceChecker $convergenceChecker;
    protected EventDispatcher $eventDispatcher;

    public function run(array $models, SimulationState $state, array $options): array
    {
        $results = [];
        $iteration = 0;

        while ($iteration < $options['iterations'] && !$this->shouldStop($state)) {
            // Run single iteration
            $iterationResults = $this->runIteration($models, $state, $options);
            
            // Check convergence
            if ($this->convergenceChecker->hasConverged($iterationResults)) {
                break;
            }

            // Store results
            $results[] = $iterationResults;
            
            // Update state
            $this->updateState($state, $iterationResults);
            
            $iteration++;
        }

        return $results;
    }

    protected function runIteration(array $models, SimulationState $state, array $options): array
    {
        $timeStepResults = [];

        for ($step = 0; $step < $options['timeSteps']; $step++) {
            // Execute each model
            foreach ($models as $model) {
                $result = $model->execute($state, $step);
                $timeStepResults[$step][$model->getName()] = $result;
                
                // Update state after each model execution
                $state->update($result);
            }

            // Dispatch step completion event
            $this->eventDispatcher->dispatch(new TimeStepCompleted($step, $state));
        }

        return $timeStepResults;
    }
}

class ResultsProcessor
{
    protected MetricsCalculator $metricsCalculator;
    protected StatisticalAnalyzer $statisticalAnalyzer;
    protected VisualizationGenerator $visualizer;

    public function process(array $results, array $options): SimulationResult
    {
        // Calculate metrics
        $metrics = $this->calculateMetrics($results, $options['metrics']);

        // Perform statistical analysis
        $statistics = $this->statisticalAnalyzer->analyze($results);

        // Generate visualizations
        $visualizations = $this->visualizer->generate($results);

        return new SimulationResult([
            'raw_results' => $results,
            'metrics' => $metrics,
            'statistics' => $statistics,
            'visualizations' => $visualizations,
            'insights' => $this->generateInsights($metrics, $statistics)
        ]);
    }

    protected function calculateMetrics(array $results, array $requiredMetrics): array
    {
        $metrics = [];

        foreach ($requiredMetrics as $metric) {
            $metrics[$metric] = $this->metricsCalculator->calculate(
                $metric,
                $results,
                $this->getMetricOptions($metric)
            );
        }

        return $metrics;
    }
}

class StateManager
{
    protected array $initialConditions;
    protected array $constraints;
    protected array $validationRules;

    public function initialize(SimulationConfig $config): SimulationState
    {
        // Set initial conditions
        $state = new SimulationState($this->initialConditions);

        // Apply constraints
        $this->applyConstraints($state, $this->constraints);

        // Validate initial state
        $this->validateState($state);

        return $state;
    }

    public function update(SimulationState $state, array $changes): void
    {
        // Apply changes
        foreach ($changes as $key => $value) {
            if ($this->isValidChange($key, $value, $state)) {
                $state->set($key, $value);
            }
        }

        // Enforce constraints
        $this->enforceConstraints($state);

        // Validate new state
        $this->validateState($state);
    }

    protected function enforceConstraints(SimulationState $state): void
    {
        foreach ($this->constraints as $constraint) {
            if (!$constraint->isSatisfied($state)) {
                $constraint->enforce($state);
            }
        }
    }
}

class SimulationConfig
{
    protected array $models;
    protected array $parameters;
    protected array $convergenceCriteria;
    protected array $metrics;

    public function getRequiredModels(): array
    {
        return array_filter(
            $this->models,
            fn($model) => $model['required'] === true
        );
    }

    public function getConvergenceCriteria(): array
    {
        return [
            'tolerance' => $this->convergenceCriteria['tolerance'] ?? 0.001,
            'minIterations' => $this->convergenceCriteria['minIterations'] ?? 10,
            'maxIterations' => $this->convergenceCriteria['maxIterations'] ?? 1000
        ];
    }

    public function getRequiredMetrics(): array
    {
        return array_map(
            fn($metric) => $metric['name'],
            array_filter($this->metrics, fn($metric) => $metric['required'])
        );
    }
}

class SimulationResult
{
    protected array $rawResults;
    protected array $metrics;
    protected array $statistics;
    protected array $visualizations;
    protected array $insights;

    public function getSummary(): array
    {
        return [
            'convergence' => $this->getConvergenceStatus(),
            'key_metrics' => $this->getKeyMetrics(),
            'statistical_summary' => $this->getStatisticalSummary(),
            'significant_findings' => $this->getSignificantFindings()
        ];
    }

    public function getConvergenceStatus(): array
    {
        return [
            'converged' => $this->hasConverged(),
            'iterations_required' => $this->getIterationCount(),
            'final_error' => $this->getFinalError(),
            'convergence_path' => $this->getConvergencePath()
        ];
    }

    protected function getSignificantFindings(): array
    {
        return array_filter(
            $this->insights,
            fn($insight) => $insight['significance'] > 0.8
        );
    }
}
```
