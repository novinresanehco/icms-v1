<?php

namespace App\Core\Metrics\Console\Commands;

use Illuminate\Console\Command;
use App\Core\Metrics\Reporting\ReportGenerator;

class GenerateMetricsReport extends Command
{
    protected $signature = 'metrics:report
                          {metric : The metric to generate report for}
                          {--from= : Start timestamp}
                          {--to= : End timestamp}
                          {--type=timeseries : Report type (timeseries/summary)}
                          {--format=json : Output format (json/csv)}';

    protected $description = 'Generate a metrics report';

    public function handle(ReportGenerator $generator)
    {
        $from = $this->option('from') ?? strtotime('-24 hours');
        $to = $this->option('to') ?? time();
        
        $metrics = $this->getMetrics(
            $this->argument('metric'),
            $from,
            $to
        );

        $report = $generator->generate(
            $metrics,
            $this->option('type'),
            $this->option('format')
        );

        $this->output->writeln($report);
    }

    private function getMetrics(string $metric, int $from, int $to): array
    {
        // Implementation to fetch metrics from storage
        return [];
    }
}

class CleanupMetrics extends Command
{
    protected $signature = 'metrics:cleanup {--older-than=30 : Days to keep}';
    
    protected $description = 'Cleanup old metrics data';

    public function handle()
    {
        $threshold = now()->subDays(
            $this->option('older-than')
        )->timestamp;

        // Implementation for cleanup
        $this->info('Metrics cleanup completed');
    }
}
